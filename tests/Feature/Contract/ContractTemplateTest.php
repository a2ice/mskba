<?php

namespace Tests\Feature\Contract;

use App\Filament\Backoffice\Resources\Leagues\BackofficeLeagueEditor;
use App\Modules\Contract\Application\Services\ContractTemplateService;
use App\Modules\Contract\Application\Services\EntityContractManager;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum as Scope;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Contract\Domain\Models\ContractTemplate;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\League\Domain\Enums\LeaguePermissionEnum;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ContractTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_personal_template_is_reusable_and_does_not_mutate_existing_contracts(): void
    {
        $admin = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
        ]);
        $other = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        $league = app(BackofficeLeagueEditor::class)->create(['name' => 'Лига шаблонов', 'status' => 'active', 'owner_user_id' => $admin->id]);
        $membership = ContractMembership::query()->where('scope_type', Scope::LEAGUE->value)->where('scope_id', $league->id)->firstOrFail();
        $route = route('account.contract-templates.store', $membership->id);
        $this->post($route, [
            'template_name' => 'Помощник лиги',
            'template_permissions' => [LeaguePermissionEnum::EDIT->value],
        ])->assertRedirect();

        $template = ContractTemplate::query()->firstOrFail();
        $this->assertNull($template->scope_id);
        $this->assertSame([LeaguePermissionEnum::EDIT->value], $template->permissions);
        $this->assertCount(1, app(ContractTemplateService::class)->available($admin, Scope::LEAGUE, $league->id));

        $contracts = app(EntityContractManager::class);
        $invite = $contracts->invite(Scope::LEAGUE, $league->id, $admin, $other,
            $template->name, $template->permissions);
        $this->assertSame([LeaguePermissionEnum::EDIT->value], $invite->contract->permissions->pluck('permission')->all());

        $this->put(route('account.contract-templates.update', [$membership->id, $template->id]), [
            'template_name' => 'Помощник лиги с турнирами',
            'template_permissions' => [LeaguePermissionEnum::MANAGE_TOURNAMENTS->value],
            'shared' => 1,
        ])->assertRedirect();
        $this->assertSame([LeaguePermissionEnum::EDIT->value],
            $invite->contract->fresh()->permissions->pluck('permission')->all());

        $this->assertSame($league->id, $template->fresh()->scope_id);
        $this->assertTrue($template->fresh()->shared);
        $this->delete(route('account.contract-templates.destroy', [$membership->id, $template->id]))
            ->assertRedirect();
        $this->assertDatabaseCount('contract_templates', 0);
    }

    public function test_template_owner_cannot_save_rights_not_held(): void
    {
        $admin = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
        ]);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));
        $league = app(BackofficeLeagueEditor::class)->create(['name' => 'Лига ограничений', 'status' => 'active', 'owner_user_id' => $admin->id]);
        $membership = ContractMembership::query()->where('scope_type', Scope::LEAGUE->value)->where('scope_id', $league->id)->firstOrFail();

        $this->post(route('account.contract-templates.store', $membership->id), [
            'template_name' => 'Чужое право',
            'template_permissions' => ['backoffice.contract.manage'],
        ])->assertSessionHasErrors('template_permissions');

        $this->assertDatabaseCount('contract_templates', 0);
    }
}
