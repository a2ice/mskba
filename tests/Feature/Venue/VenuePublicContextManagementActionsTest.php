<?php

namespace Tests\Feature\Venue;

use App\Modules\Identity\Application\Services\CurrentActorResolver;
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

    public function test_public_venue_and_court_pages_keep_edit_in_context_menu_only_for_editor(): void
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
                ->assertSee('>Редактировать</a>', false)
                ->assertDontSee('>Промокоды</a>', false)
                ->assertDontSee('Управление промокодами')
                ->assertDontSee('>Найти</button>', false)
                ->assertDontSee('>Найти</a>', false)
                ->assertDontSee('app-context-actions__nested', false)
                ->assertSee('href="'.$editUrl.'"', false)
                ->assertDontSee('app-entity-edit-link', false)
                ->assertDontSee('button secondary large" href="'.$editUrl.'"', false);
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
            ->assertSee('Редактирование')
            ->assertDontSee('aria-current="page">Страница</span>', false);
    }
}
