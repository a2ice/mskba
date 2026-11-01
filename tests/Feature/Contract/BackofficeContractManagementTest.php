<?php

namespace Tests\Feature\Contract;

use App\Filament\Backoffice\Resources\Contracts\ContractResource;
use App\Filament\Backoffice\Resources\Leagues\BackofficeLeagueEditor;
use App\Modules\Contract\Application\Services\BackofficeContractEditor;
use App\Modules\Contract\Application\Services\EntityContractManager;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum as Scope;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\League\Domain\Enums\LeaguePermissionEnum;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class BackofficeContractManagementTest extends TestCase
{
    use RefreshDatabase;

    private function user(UserSystemRoleEnum $role = UserSystemRoleEnum::USER): User
    {
        return User::factory()->create(['status' => UserStatusEnum::CONFIRMED, 'system_role' => $role]);
    }

    private function office(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));
    }

    /** @return array{0: User, 1: User, 2: \App\Modules\League\Domain\Models\League} */
    private function leagueFixture(): array
    {
        $admin = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $owner = $this->user();
        $this->office($admin);
        $league = app(BackofficeLeagueEditor::class)->create([
            'name' => 'Лига администрирования контрактов',
            'status' => 'active',
            'owner_user_id' => $owner->id,
        ]);
        $ownerMembership = ContractMembership::query()
            ->where('scope_type', Scope::LEAGUE->value)
            ->where('scope_id', $league->id)
            ->where('access_level', 'owner')->firstOrFail();
        app(EntityContractManager::class)->respond($ownerMembership, $owner, true);
        return [$admin, $owner, $league];
    }

    public function test_backoffice_permissions_are_independent_and_registry_is_available(): void
    {
        [$admin, $owner] = $this->leagueFixture();

        $this->office($owner);
        $this->assertFalse(ContractResource::canViewAny());
        $this->get(ContractResource::getUrl('index'))->assertForbidden();

        $this->office($admin);
        $this->assertTrue(ContractResource::canViewAny());
        $this->get(ContractResource::getUrl('index'))->assertOk();
    }

    public function test_contract_expiry_notes_and_administrative_revision_are_persisted(): void
    {
        [$admin, $owner, $league] = $this->leagueFixture();
        $recipient = $this->user();
        $date = now()->addDays(10)->startOfMinute()->toDateTimeString();
        $member = app(EntityContractManager::class)->invite(
            Scope::LEAGUE, $league->id, $owner, $recipient, 'Редактор', [LeaguePermissionEnum::EDIT->value],
            $date, 'Назначен на осенний сезон',
        );
        $this->assertNotNull($member->contract->expires_at);
        $this->assertSame('Назначен на осенний сезон', $member->contract->notes);

        $this->office($admin);
        app(BackofficeContractEditor::class)->update($member->contract, [
            'name' => 'Редактор лиги',
            'notes' => 'Ответственный за новости',
            'status' => 'active',
            'expires_at' => now()->addDays(5)->toDateTimeString(),
            'permissions' => [LeaguePermissionEnum::EDIT->value],
            'change_reason' => 'Сокращён срок работы',
        ]);

        $this->assertSame('Редактор лиги', $member->contract->fresh()->name);
        $this->assertDatabaseHas('contract_change_logs', [
            'contract_id' => $member->contract_id,
            'changed_by_user_id' => $admin->id,
            'reason' => 'Сокращён срок работы',
        ]);
    }

    public function test_owner_contract_cannot_be_revoked_through_editor(): void
    {
        [$admin, , $league] = $this->leagueFixture();
        $owner = ContractMembership::query()
            ->where('scope_type', Scope::LEAGUE->value)
            ->where('scope_id', $league->id)
            ->where('access_level', 'owner')->firstOrFail()->contract;

        $this->office($admin);
        $this->expectException(ValidationException::class);
        app(BackofficeContractEditor::class)->update($owner, [
            'name' => $owner->name,
            'status' => 'inactive',
            'change_reason' => 'Попытка отмены без передачи владения',
        ]);
    }

    public function test_delegation_cannot_outlive_the_permissions_of_the_issuer(): void
    {
        [, $owner, $league] = $this->leagueFixture();
        $manager = $this->user();
        $delegate = $this->user();
        $expiry = now()->addDays(3)->startOfMinute();
        $rights = [LeaguePermissionEnum::EDIT->value, LeaguePermissionEnum::MANAGE_CONTRACTS->value];
        $managerMembership = app(EntityContractManager::class)->invite(
            Scope::LEAGUE, $league->id, $owner, $manager, 'Руководитель проекта', $rights,
            $expiry->toDateTimeString(),
        );
        app(EntityContractManager::class)->respond($managerMembership, $manager, true);

        $child = app(EntityContractManager::class)->invite(
            Scope::LEAGUE, $league->id, $manager, $delegate, 'Редактор', [LeaguePermissionEnum::EDIT->value],
        );
        $this->assertSame($expiry->timestamp, $child->contract->expires_at->timestamp);

        $third = $this->user();
        $this->expectException(ValidationException::class);
        app(EntityContractManager::class)->invite(
            Scope::LEAGUE, $league->id, $manager, $third, 'Другой редактор',
            [LeaguePermissionEnum::EDIT->value], now()->addDays(8)->toDateTimeString(),
        );
    }
}
