<?php

namespace Tests\Feature\Backoffice;

use App\Filament\Backoffice\Resources\BackofficeUsers\BackofficeUserResource;
use App\Filament\Backoffice\Resources\BackofficeUsers\Pages\EditBackofficeUser;
use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\Finance\Application\Queries\GetAccountWalletOverview;
use App\Modules\Finance\Application\Services\BackofficeUserWalletFunding;
use App\Modules\Finance\Application\Services\SuperadminWalletBonusGrant;
use App\Modules\Finance\Application\UseCases\CreditWalletHandler;
use App\Modules\Finance\Application\UseCases\EnsureWalletHandler;
use App\Modules\Finance\Domain\Enums\WalletBalanceTypeEnum;
use App\Modules\Finance\Domain\Enums\WalletOperationTypeEnum;
use App\Modules\Finance\Domain\Enums\WalletOwnerTypeEnum;
use App\Modules\Finance\Domain\Exceptions\InsufficientWalletBalanceException;
use App\Modules\Finance\Domain\Models\WalletOperation;
use App\Modules\Identity\Application\Services\UserOperationalPermissionChecker;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserOperationalPermission;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class BackofficeUserWalletManagementTest extends TestCase
{
    use RefreshDatabase;

    private function person(UserSystemRoleEnum $role = UserSystemRoleEnum::USER): User
    {
        $user = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => $role,
        ]);
        $user->createProfile([]);

        return $user;
    }

    private function credit(User $user, int $minor): void
    {
        $wallet = app(EnsureWalletHandler::class)->handle(WalletOwnerTypeEnum::USER, $user->id);
        app(CreditWalletHandler::class)->handle(
            wallet: $wallet,
            balanceType: WalletBalanceTypeEnum::BONUS,
            amountMinor: $minor,
            operationType: WalletOperationTypeEnum::BONUS_GRANT,
            idempotencyKey: 'backoffice-wallet-test-'.$user->id.'-'.$minor,
        );
    }

    private function balances(User $user): array
    {
        $wallet = app(EnsureWalletHandler::class)->handle(WalletOwnerTypeEnum::USER, $user->id);
        return [(int) $wallet->refresh()->real_balance_minor, (int) $wallet->bonus_balance_minor];
    }

    private function grantPermission(User $user, Permission $permission): void
    {
        UserOperationalPermission::query()->updateOrCreate(
            ['user_id' => $user->id, 'permission' => $permission->value],
            ['is_allowed' => true],
        );
    }

    public function test_superadmin_can_view_own_wallet_and_other_users_wallet_with_action_visibility(): void
    {
        $super = $this->person(UserSystemRoleEnum::SUPERADMIN);
        $recipient = $this->person();
        $this->actingAs($super);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        $this->get(BackofficeUserResource::getUrl('edit', ['record' => $super]))
            ->assertOk()->assertSee('Кошелёк');

        Livewire::test(EditBackofficeUser::class, ['record' => $super->getRouteKey()])
            ->assertActionVisible('grantOwnWalletBonus')
            ->assertActionHidden('fundUserWalletBonus');

        Livewire::test(EditBackofficeUser::class, ['record' => $recipient->getRouteKey()])
            ->assertActionVisible('fundUserWalletBonus')
            ->assertActionHidden('grantOwnWalletBonus');
    }

    public function test_superadmin_own_bonus_grant_uses_existing_password_check_and_financial_ledger(): void
    {
        $super = $this->person(UserSystemRoleEnum::SUPERADMIN);
        $super->forceFill(['password' => 'CorrectWalletPass123!'])->save();
        $grant = app(SuperadminWalletBonusGrant::class);

        $this->expectException(\App\Modules\Finance\Domain\Exceptions\WalletException::class);
        $grant->grant($super, 'wrong-password', 10000, (string) Str::uuid());
    }

    public function test_superadmin_grant_creates_bonus_only_and_is_idempotent(): void
    {
        $super = $this->person(UserSystemRoleEnum::SUPERADMIN);
        $super->forceFill(['password' => 'CorrectWalletPass123!'])->save();
        $key = (string) Str::uuid();
        $grant = app(SuperadminWalletBonusGrant::class);
        $first = $grant->grant($super, 'CorrectWalletPass123!', 20000, $key);
        $second = $grant->grant($super, 'CorrectWalletPass123!', 20000, $key);

        $this->assertSame($first->id, $second->id);
        $this->assertSame([0, 20000], $this->balances($super));
        $this->assertSame(1, WalletOperation::query()->where('idempotency_key', $key)->count());
    }

    public function test_bonus_funding_is_two_sided_idempotent_and_visible_in_both_histories(): void
    {
        $super = $this->person(UserSystemRoleEnum::SUPERADMIN);
        $recipient = $this->person();
        $this->credit($super, 100000);
        $funding = app(BackofficeUserWalletFunding::class);
        $this->actingAs($super);

        $key = (string) Str::uuid();
        $first = $funding->fund($super, $recipient, $super->id, 32500, 'Бонус за участие в турнире', $key);
        $second = $funding->fund($super, $recipient, $super->id, 32500, 'Бонус за участие в турнире', $key);

        $this->assertFalse($first->replayed);
        $this->assertTrue($second->replayed);
        $this->assertSame($first->operation->id, $second->operation->id);
        $this->assertSame([0, 67500], $this->balances($super));
        $this->assertSame([0, 32500], $this->balances($recipient));
        $this->assertSame([-32500, 32500],
            $first->operation->entries->sortBy('id')->pluck('amount_minor')->values()->all());
        $this->assertSame($super->id, $first->operation->performed_by_user_id);
        $this->assertSame('backoffice_superadmin_bonus_transfer', $first->operation->metadata['source']);
        $this->assertSame($super->id, $first->operation->metadata['source_superadmin_user_id']);
        $this->assertSame('Бонус за участие в турнире', $first->operation->metadata['reason']);
        $this->assertSame(1, AuditLog::query()->where('event', 'backoffice_user_bonus_funded')->count());

        $recipientHistory = app(GetAccountWalletOverview::class)->handle($recipient);
        $sourceHistory = app(GetAccountWalletOverview::class)->handle($super);
        $recipientOperation = collect($recipientHistory['operations'])->firstWhere('id', $first->operation->id);
        $sourceOperation = collect($sourceHistory['operations'])->firstWhere('id', $first->operation->id);
        $this->assertSame(32500, $recipientOperation['bonusDeltaMinor']);
        $this->assertSame(-32500, $sourceOperation['bonusDeltaMinor']);
    }

    public function test_funding_without_explicit_permission_is_denied_even_for_admin(): void
    {
        $super = $this->person(UserSystemRoleEnum::SUPERADMIN);
        $admin = $this->person(UserSystemRoleEnum::ADMIN);
        $recipient = $this->person();
        $this->credit($super, 30000);
        $funding = app(BackofficeUserWalletFunding::class);

        $this->assertFalse(app(UserOperationalPermissionChecker::class)->allows($admin, Permission::WALLET_USER_BALANCE_CREDIT));
        $this->assertFalse($funding->canFund($admin, $recipient));
        $this->actingAs($admin);

        $this->expectException(AuthorizationException::class);
        $funding->fund($admin, $recipient, $super->id, 10000, 'Недопустимое начисление', (string) Str::uuid());
    }

    public function test_delegated_admin_can_fund_only_after_explicit_grant_and_is_recorded_as_operator(): void
    {
        $super = $this->person(UserSystemRoleEnum::SUPERADMIN);
        $admin = $this->person(UserSystemRoleEnum::ADMIN);
        $recipient = $this->person();
        $this->credit($super, 60000);
        $this->grantPermission($admin, Permission::WALLET_USER_BALANCE_CREDIT);
        $this->grantPermission($admin, Permission::EDIT_USER_PERSONAL_DATA);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        $funding = app(BackofficeUserWalletFunding::class);
        $this->assertTrue($funding->canFund($admin, $recipient));
        $this->assertTrue(BackofficeUserResource::canEdit($recipient));
        $this->assertArrayHasKey($super->id, $funding->sourceOptions($admin));

        $result = $funding->fund($admin, $recipient, $super->id, 12500, 'Поощрение нового игрока', (string) Str::uuid());
        $this->assertSame($admin->id, $result->operation->performed_by_user_id);
        $this->assertSame($admin->id, $result->operation->metadata['admin_operator_user_id']);
        $this->assertSame([0, 47500], $this->balances($super));
        $this->assertSame([0, 12500], $this->balances($recipient));

        Livewire::test(EditBackofficeUser::class, ['record' => $recipient->getRouteKey()])
            ->assertActionVisible('fundUserWalletBonus')
            ->assertActionHidden('grantOwnWalletBonus');
    }

    public function test_funding_cannot_debit_other_superadmins_wallet_or_fund_superadmin(): void
    {
        $super = $this->person(UserSystemRoleEnum::SUPERADMIN);
        $anotherSuper = $this->person(UserSystemRoleEnum::SUPERADMIN);
        $recipient = $this->person();
        $this->credit($anotherSuper, 30000);
        $this->actingAs($super);
        $funding = app(BackofficeUserWalletFunding::class);

        $this->assertFalse($funding->canFund($super, $anotherSuper));
        $this->expectException(AuthorizationException::class);
        $funding->fund($super, $recipient, $anotherSuper->id, 10000, 'Попытка чужого списания', (string) Str::uuid());
    }

    public function test_insufficient_source_bonus_balance_rolls_back_both_wallets(): void
    {
        $super = $this->person(UserSystemRoleEnum::SUPERADMIN);
        $recipient = $this->person();
        $this->actingAs($super);
        $key = (string) Str::uuid();

        try {
            app(BackofficeUserWalletFunding::class)->fund(
                $super, $recipient, $super->id, 10000, 'Бонус за мероприятие', $key,
            );
            $this->fail('Funding without source balance must fail.');
        } catch (InsufficientWalletBalanceException) {
            $this->assertSame([0, 0], $this->balances($super));
            $this->assertSame([0, 0], $this->balances($recipient));
            $this->assertDatabaseMissing('wallet_operations', ['idempotency_key' => $key]);
            $this->assertSame(0, AuditLog::query()->where('event', 'backoffice_user_bonus_funded')->count());
        }
    }
}
