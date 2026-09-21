<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Application\UseCases\CreditWalletHandler;
use App\Modules\Finance\Application\UseCases\EnsureWalletHandler;
use App\Modules\Finance\Application\UseCases\TransferUserWalletBalanceHandler;
use App\Modules\Finance\Domain\Enums\WalletBalanceTypeEnum;
use App\Modules\Finance\Domain\Enums\WalletOperationTypeEnum;
use App\Modules\Finance\Domain\Enums\WalletOwnerTypeEnum;
use App\Modules\Finance\Domain\Exceptions\InsufficientWalletBalanceException;
use App\Modules\Finance\Domain\Exceptions\WalletException;
use App\Modules\Finance\Domain\Models\WalletOperation;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use App\Modules\Notification\Domain\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class UserWalletTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_bonus_transfer_moves_value_atomically_between_user_wallets(): void
    {
        $sender = $this->confirmedUser('sender');
        $recipient = $this->confirmedUser('recipient');
        $wallets = app(EnsureWalletHandler::class);
        $source = $wallets->handle(WalletOwnerTypeEnum::USER, $sender->id);
        $destination = $wallets->handle(WalletOwnerTypeEnum::USER, $recipient->id);

        app(CreditWalletHandler::class)->handle(
            $source,
            WalletBalanceTypeEnum::BONUS,
            100_000,
            WalletOperationTypeEnum::BONUS_GRANT,
            'transfer-source-credit',
        );

        $result = app(TransferUserWalletBalanceHandler::class)->handle(
            $sender,
            $recipient,
            40_000,
            WalletBalanceTypeEnum::BONUS,
            'transfer-1',
        );

        $this->assertFalse($result->replayed);
        $this->assertSame(60_000, $source->refresh()->bonus_balance_minor);
        $this->assertSame(40_000, $destination->refresh()->bonus_balance_minor);
        $this->assertSame(
            [-40_000, 40_000],
            $result->operation->entries->sortBy('id')->pluck('amount_minor')->values()->all(),
        );
        $this->assertSame(100_000, $source->bonus_balance_minor + $destination->bonus_balance_minor);
    }

    public function test_bonus_transfer_is_idempotent(): void
    {
        $sender = $this->confirmedUser('sender');
        $recipient = $this->confirmedUser('recipient');
        $source = app(EnsureWalletHandler::class)->handle(WalletOwnerTypeEnum::USER, $sender->id);

        app(CreditWalletHandler::class)->handle(
            $source,
            WalletBalanceTypeEnum::BONUS,
            50_000,
            WalletOperationTypeEnum::BONUS_GRANT,
            'idempotent-source-credit',
        );

        $first = app(TransferUserWalletBalanceHandler::class)->handle(
            $sender,
            $recipient,
            10_000,
            WalletBalanceTypeEnum::BONUS,
            'same-transfer',
        );
        $second = app(TransferUserWalletBalanceHandler::class)->handle(
            $sender,
            $recipient,
            10_000,
            WalletBalanceTypeEnum::BONUS,
            'same-transfer',
        );

        $this->assertFalse($first->replayed);
        $this->assertTrue($second->replayed);
        $this->assertSame(40_000, $source->refresh()->bonus_balance_minor);
        $this->assertDatabaseCount('wallet_ledger_entries', 3);
    }

    public function test_insufficient_bonus_rolls_back_transfer(): void
    {
        $sender = $this->confirmedUser('sender');
        $recipient = $this->confirmedUser('recipient');
        $source = app(EnsureWalletHandler::class)->handle(WalletOwnerTypeEnum::USER, $sender->id);

        app(CreditWalletHandler::class)->handle(
            $source,
            WalletBalanceTypeEnum::BONUS,
            5_000,
            WalletOperationTypeEnum::BONUS_GRANT,
            'small-transfer-source',
        );

        try {
            app(TransferUserWalletBalanceHandler::class)->handle(
                $sender,
                $recipient,
                10_000,
                WalletBalanceTypeEnum::BONUS,
                'too-large-transfer',
            );
            $this->fail('Insufficient transfer must fail.');
        } catch (InsufficientWalletBalanceException) {
            $this->assertSame(5_000, $source->refresh()->bonus_balance_minor);
            $this->assertDatabaseMissing('wallet_operations', ['idempotency_key' => 'too-large-transfer']);
        }
    }

    public function test_real_transfer_is_disabled_by_default(): void
    {
        $sender = $this->confirmedUser('sender');
        $recipient = $this->confirmedUser('recipient');
        $source = app(EnsureWalletHandler::class)->handle(WalletOwnerTypeEnum::USER, $sender->id);

        app(CreditWalletHandler::class)->handle(
            $source,
            WalletBalanceTypeEnum::REAL,
            20_000,
            WalletOperationTypeEnum::TOP_UP,
            'real-transfer-source',
        );

        $this->expectException(WalletException::class);
        $this->expectExceptionMessage('Переводы основного баланса пока отключены.');

        app(TransferUserWalletBalanceHandler::class)->handle(
            $sender,
            $recipient,
            5_000,
            WalletBalanceTypeEnum::REAL,
            'real-transfer-disabled',
        );
    }

    public function test_transfer_to_same_canonical_identity_is_rejected(): void
    {
        $sender = $this->confirmedUser('sender');
        $alias = User::factory()->create([
            'canonical_user_id' => $sender->id,
            'status' => UserStatusEnum::CONFIRMED->value,
        ]);

        $this->expectException(WalletException::class);
        $this->expectExceptionMessage('Нельзя перевести средства самому себе.');

        app(TransferUserWalletBalanceHandler::class)->handle(
            $sender,
            $alias,
            100,
            WalletBalanceTypeEnum::BONUS,
            'self-transfer',
        );
    }

    public function test_wallet_ui_transfer_creates_finance_notification_only_for_recipient(): void
    {
        $sender = $this->confirmedUser('sender');
        $recipient = $this->confirmedUser('olsen');
        $source = app(EnsureWalletHandler::class)->handle(WalletOwnerTypeEnum::USER, $sender->id);

        app(CreditWalletHandler::class)->handle(
            $source,
            WalletBalanceTypeEnum::BONUS,
            50_000,
            WalletOperationTypeEnum::BONUS_GRANT,
            'ui-transfer-source',
        );

        $this->actingAs($sender)
            ->post(route('account.wallet.transfers.store'), [
                'recipient' => '@olsen',
                'amount' => '125,50',
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertRedirect(route('account.wallet'))
            ->assertSessionHas('status');

        $destination = app(EnsureWalletHandler::class)->handle(WalletOwnerTypeEnum::USER, $recipient->id);
        $this->assertSame(37_450, $source->refresh()->bonus_balance_minor);
        $this->assertSame(12_550, $destination->refresh()->bonus_balance_minor);

        $notification = UserNotification::query()->sole();
        $this->assertSame($recipient->id, $notification->user_id);
        $this->assertSame(UserNotificationTypeEnum::FINANCE, $notification->type);
        $this->assertStringContainsString('@sender', $notification->body);
        $this->assertStringContainsString('125,50 ₽', $notification->body);

        $this->actingAs($sender)
            ->get(route('account.wallet'))
            ->assertOk()
            ->assertSee('→ @olsen');

        $this->actingAs($recipient)
            ->get(route('account.wallet'))
            ->assertOk()
            ->assertSee('← @sender');
    }

    public function test_superadmin_can_claim_bootstrap_bonus_only_once(): void
    {
        $superadmin = $this->confirmedUser('superadmin', UserSystemRoleEnum::SUPERADMIN);

        $this->actingAs($superadmin)
            ->get(route('account.wallet'))
            ->assertOk()
            ->assertSee('Начислить 10 000 ₽ бонусами');

        $this->actingAs($superadmin)
            ->post(route('account.wallet.bootstrap-bonus.store'))
            ->assertRedirect(route('account.wallet'));

        $this->actingAs($superadmin)
            ->post(route('account.wallet.bootstrap-bonus.store'))
            ->assertRedirect(route('account.wallet'));

        $wallet = app(EnsureWalletHandler::class)->handle(WalletOwnerTypeEnum::USER, $superadmin->id);
        $this->assertSame(1_000_000, $wallet->bonus_balance_minor);
        $this->assertSame(1, WalletOperation::query()->where('type', WalletOperationTypeEnum::BONUS_GRANT->value)->count());

        $this->actingAs($superadmin)
            ->get(route('account.wallet'))
            ->assertOk()
            ->assertDontSee('Начислить 10 000 ₽ бонусами');
    }

    public function test_regular_user_cannot_claim_superadmin_bootstrap_bonus(): void
    {
        $user = $this->confirmedUser('regular');

        $this->actingAs($user)
            ->post(route('account.wallet.bootstrap-bonus.store'))
            ->assertForbidden();
    }

    private function confirmedUser(string $nickname, UserSystemRoleEnum $role = UserSystemRoleEnum::USER): User
    {
        $user = User::factory()->create([
            'username' => $nickname.'_login',
            'status' => UserStatusEnum::CONFIRMED->value,
            'system_role' => $role->value,
        ]);
        $user->forceFill(['nickname' => $nickname])->save();

        return $user->refresh();
    }
}
