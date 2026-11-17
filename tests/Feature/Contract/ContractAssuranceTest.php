<?php

namespace Tests\Feature\Contract;

use App\Filament\Backoffice\Resources\Leagues\BackofficeLeagueEditor;
use App\Modules\Contract\Application\Services\ContractAssurance;
use App\Modules\Contract\Application\Services\EntityContractManager;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum as Scope;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\League\Domain\Enums\LeaguePermissionEnum;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ContractAssuranceTest extends TestCase
{
    use RefreshDatabase;

    public function test_evidence_backed_contract_grants_no_rights_before_independent_review(): void
    {
        $issuer = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
        ]);
        $recipient = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $this->actingAs($issuer);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));
        $league = app(BackofficeLeagueEditor::class)->create([
            'name' => 'Лига подтверждений', 'status' => 'active', 'owner_user_id' => $issuer->id,
        ]);

        $contracts = app(EntityContractManager::class);
        $delegate = $contracts->invite(Scope::LEAGUE, $league->id, $issuer, $recipient,
            'Представитель лиги', [LeaguePermissionEnum::EDIT->value], null, null, 'evidence_backed');

        $this->assertSame('required', $delegate->contract->verification_requirement);
        $this->assertSame('pending', $delegate->contract->verification->status);
        $contracts->respond($delegate, $recipient, true);
        $this->assertFalse($contracts->allows($recipient, Scope::LEAGUE, $league->id, LeaguePermissionEnum::EDIT->value));

        app(ContractAssurance::class)->recordVerified($delegate->contract, 'manual_evidence', 123, $issuer->id);
        $this->assertTrue($contracts->allows($recipient, Scope::LEAGUE, $league->id, LeaguePermissionEnum::EDIT->value));

        $delegate->contract->verification()->update(['expires_at' => now()->subMinute()]);
        $this->assertFalse($contracts->allows($recipient, Scope::LEAGUE, $league->id, LeaguePermissionEnum::EDIT->value));
    }

    public function test_unverified_owner_cannot_delegate_confirmation_required_rights(): void
    {
        $issuer = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
        ]);
        $recipient = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $this->actingAs($issuer);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));
        $league = app(BackofficeLeagueEditor::class)->create([
            'name' => 'Лига ограниченного подтверждения', 'status' => 'active', 'owner_user_id' => $issuer->id,
        ]);
        $owner = ContractMembership::query()
            ->where('scope_type', Scope::LEAGUE->value)->where('scope_id', $league->id)
            ->where('access_level', 'owner')->firstOrFail();
        $owner->contract->update(['verification_requirement' => 'required']);
        $owner->contract->verification()->create(['status' => 'pending']);
        $this->assertFalse(app(EntityContractManager::class)->allows(
            $issuer, Scope::LEAGUE, $league->id, LeaguePermissionEnum::MANAGE_CONTRACTS->value,
        ));

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(EntityContractManager::class)->invite(Scope::LEAGUE, $league->id,
            $issuer, $recipient, 'Недопустимая выдача', [LeaguePermissionEnum::EDIT->value]);
    }
}
