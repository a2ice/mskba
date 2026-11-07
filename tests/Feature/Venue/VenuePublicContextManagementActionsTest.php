<?php

namespace Tests\Feature\Venue;

use App\Filament\Backoffice\Resources\Venues\VenueResource;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Contract\Domain\Enums\ContractFamilyEnum;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Enums\VenueMembershipAccessLevelEnum;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class VenuePublicContextManagementActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('themes.active', 'mskba_app');
        app()->forgetInstance(\App\Presentation\Theming\ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));
    }

    public function test_bootstrap_creator_without_contract_has_no_account_edit_shortcut(): void
    {
        $owner = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $venue = Venue::factory()->create([
            'name' => 'Школа №1794',
            'status' => VenueStatusEnum::CONFIRMED,
            'created_by_actor_id' => app(CurrentActorResolver::class)->resolve($owner, null)->id,
        ]);
        $court = $venue->primaryCourt()->firstOrFail();
        $editUrl = route('account.venues.edit', $venue->routeIdentifier());

        $guest = $this->get(route('venues.show', $venue->routeIdentifier()))->assertOk();
        $guest->assertDontSee('href="'.$editUrl.'"', false);
        $guest->assertDontSee('Управление промокодами')
            ->assertDontSee('>Найти</button>', false);

        foreach ([
            route('venues.show', $venue->routeIdentifier()),
            route('venues.courts.show', [$venue->routeIdentifier(), $court->routeIdentifier()]),
        ] as $url) {
            $response = $this->actingAs($owner)->get($url)->assertOk();
            $response->assertSee('aria-label="Контекстные действия"', false)
                ->assertDontSee('>Редактировать</a>', false)
                ->assertDontSee('>Промокоды</a>', false)
                ->assertDontSee('Управление промокодами')
                ->assertDontSee('>Найти</button>', false)
                ->assertDontSee('>Найти</a>', false)
                ->assertDontSee('app-context-actions__nested', false)
                ->assertDontSee('href="'.$editUrl.'"', false)
                ->assertDontSee('app-entity-edit-link', false)
                ->assertDontSee('button secondary large" href="'.$editUrl.'"', false);
        }
    }

    public function test_create_and_edit_actions_are_visible_to_contracted_editor_and_superadmin_on_venue_and_court(): void
    {
        $creator = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $editor = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $superadmin = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
        ]);
        $stranger = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $venue = Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED,
            'created_by_actor_id' => app(CurrentActorResolver::class)->resolve($creator, null)->id,
        ]);
        $court = $venue->primaryCourt()->firstOrFail();
        $editUrl = route('account.venues.edit', $venue->routeIdentifier());
        $backofficeEditUrl = VenueResource::getUrl('edit', ['record' => $venue], panel: 'backoffice');

        // A current venue-scoped contract with EDIT, not global admin role,
        // grants the menu action. No completed editor view is required.
        $contract = Contract::query()->create([
            'family' => ContractFamilyEnum::MEMBERSHIP->value,
            'number' => fake()->unique()->bothify('MENU-####-????'),
            'name' => 'Venue edit permission',
            'status' => ContractStatusEnum::ACTIVE->value,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addDay(),
            'assigner' => UserParticipationRoleAssignerEnum::OTHER->value,
        ]);
        $contract->membership()->create([
            'scope_type' => ContractMembershipScopeTypeEnum::VENUE->value,
            'scope_id' => $venue->id,
            'user_id' => $editor->id,
            'access_level' => VenueMembershipAccessLevelEnum::ADMIN->value,
        ]);
        $contract->permissions()->create(['permission' => VenuePermissionEnum::EDIT->value]);

        foreach ([
            route('venues.show', $venue->routeIdentifier()),
            route('venues.courts.show', [$venue->routeIdentifier(), $court->routeIdentifier()]),
        ] as $url) {
            $this->actingAs($editor)->get($url)->assertOk()
                ->assertSee('data-context-placeholder="Создание площадки"', false)
                ->assertSee('href="'.$editUrl.'">Редактировать</a>', false)
                ->assertDontSee('href="'.$backofficeEditUrl.'"', false);

            $this->actingAs($superadmin)->get($url)->assertOk()
                ->assertSee('href="'.$backofficeEditUrl.'">Редактировать</a>', false)
                ->assertDontSee('href="'.$editUrl.'"', false);

            $this->actingAs($stranger)->get($url)->assertOk()
                ->assertSee('data-context-placeholder="Создание площадки"', false)
                ->assertDontSee('href="'.$editUrl.'">Редактировать</a>', false)
                ->assertDontSee('href="'.$backofficeEditUrl.'"', false);
        }
    }

    public function test_missing_app_editor_view_still_shows_venue_name_in_breadcrumbs(): void
    {
        $owner = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $venue = Venue::factory()->create([
            'name' => 'Школа №1794',
            'status' => VenueStatusEnum::CONFIRMED,
            'created_by_actor_id' => app(CurrentActorResolver::class)->resolve($owner, null)->id,
        ]);

        $this->actingAs($owner)
            ->get(route('account.venues.edit', $venue->routeIdentifier()))
            ->assertOk()
            ->assertSee('Школа №1794')
            ->assertSee('app-venue-editor__tabs', false)
            ->assertSeeInOrder(['Главная', 'Аккаунт', 'Мои контракты', 'Мои площадки', 'Школа №1794'])
            ->assertSee('href="'.route('account.contracts.venues').'"', false)
            ->assertDontSee('aria-current="page">Страница</span>', false);
    }
}
