<?php

namespace Tests\Feature\Contract;

use App\Modules\Contract\Domain\Enums\ContractFamilyEnum;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Enums\VenueMembershipAccessLevelEnum;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AccountContractsWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function membership(
        User $user,
        Venue $venue,
        ContractStatusEnum $status = ContractStatusEnum::ACTIVE,
        array $permissions = [VenuePermissionEnum::VIEW],
    ): Contract {
        $contract = Contract::query()->create([
            'family' => ContractFamilyEnum::MEMBERSHIP,
            'status' => $status,
            'number' => 'VENUE-'.$venue->id.'-'.$user->id,
            'starts_at' => now()->subDay(),
            'assigner' => UserParticipationRoleAssignerEnum::OTHER,
        ]);
        $contract->membership()->create([
            'scope_type' => ContractMembershipScopeTypeEnum::VENUE,
            'scope_id' => $venue->id,
            'user_id' => $user->id,
            'access_level' => VenueMembershipAccessLevelEnum::MANAGER->value,
        ]);
        $contract->permissions()->createMany(array_map(
            static fn (VenuePermissionEnum $p): array => ['permission' => $p->value],
            $permissions,
        ));

        return $contract;
    }

    public function test_contract_collection_filters_by_scope_and_status_without_leaking_other_users(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $other = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $mine = Venue::factory()->create(['name' => 'Моя арена']);
        $hidden = Venue::factory()->create(['name' => 'Секретная чужая арена']);
        $this->membership($user, $mine, ContractStatusEnum::ACTIVE);
        $this->membership($other, $hidden);

        $this->actingAs($user)
            ->get(route('account.contracts', ['type' => 'venue', 'status' => 'active']))
            ->assertOk()->assertSee('Моя арена')
            ->assertDontSee('Секретная чужая арена')
            ->assertSee('Все контракты')->assertSee('Площадки');

        $this->get(route('account.contracts', ['status' => 'inactive']))
            ->assertOk()->assertSee('Контрактов с такими параметрами нет.');

        $this->get(route('account.contracts', ['type' => 'bad-type']))
            ->assertStatus(302)->assertSessionHasErrors('type');
    }

    public function test_venue_cards_filter_and_modal_show_all_permission_codes_with_only_granted_checked(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $hall = Venue::factory()->create([
            'name' => 'Зал Сокол',
            'type' => VenueTypeEnum::SPORTS_HALL,
            'status' => VenueStatusEnum::CONFIRMED,
        ]);
        $this->membership($user, $hall, ContractStatusEnum::ACTIVE, [
            VenuePermissionEnum::VIEW,
            VenuePermissionEnum::MANAGE_GROUP_OFFERS,
        ]);

        $this->actingAs($user)->get(route('account.contracts.venues', [
            'q' => 'Сокол', 'specialization' => 'sports_hall', 'status' => 'active',
        ]))->assertOk()->assertSee('Зал Сокол')
            ->assertSee('data-contract-dialog-open', false)
            ->assertSee('Управление промокодами площадки')
            ->assertSee('venue.group-offers.manage')
            ->assertSee('is-granted', false)
            ->assertSee('is-disabled', false)
            ->assertSee(route('account.venues.group-offers.index', ['venue' => $hall->id]), false);

        $this->get(route('account.contracts.venues', ['specialization' => 'school']))
            ->assertOk()->assertDontSee('Зал Сокол');
        $this->get(route('account.venues'))->assertRedirect(route('account.contracts.venues'));
    }

    public function test_inactive_contract_is_listed_but_never_grants_green_permissions(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $venue = Venue::factory()->create(['name' => 'Арена бывшего доступа']);
        $this->membership($user, $venue, ContractStatusEnum::INACTIVE, [VenuePermissionEnum::EDIT]);

        $this->actingAs($user)->get(route('account.contracts.venues', ['status' => 'inactive']))
            ->assertOk()->assertSee('Арена бывшего доступа')
            ->assertSee('Контракт не действует')
            ->assertSee('Редактирование')
            ->assertDontSee('is-granted', false);
        $this->get(route('account.contracts.venues', ['status' => 'active']))
            ->assertOk()->assertDontSee('Арена бывшего доступа');
    }

    public function test_venues_and_contracts_require_login_and_do_not_include_unrelated_venues(): void
    {
        $mine = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $stranger = User::factory()->create();
        $venue = Venue::factory()->create(['name' => 'Чужая защищенная площадка']);
        $this->membership($stranger, $venue);

        $this->get(route('account.contracts'))->assertRedirect(route('login'));
        $this->get(route('account.contracts.venues'))->assertRedirect(route('login'));
        $this->actingAs($mine)->get(route('account.contracts.venues'))
            ->assertOk()->assertDontSee('Чужая защищенная площадка');
    }
}
