<?php

namespace Tests\Feature\Event;

use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Identity\Domain\Enums\ActorTypeEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\Venue;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class EventVenuePrefillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['themes.active' => 'mskba_app']);
        app()->forgetInstance(ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));
        $this->withoutVite();
    }

    private function signIn(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        Actor::factory()->create([
            'type' => ActorTypeEnum::USER->value,
            'user_id' => $user->id,
        ]);
        $this->actingAs($user);
    }

    public function test_wizard_preserves_selected_venue_and_court_in_app_create_form(): void
    {
        $this->signIn();
        $venue = Venue::factory()->create();
        $court = $venue->primaryCourt()->firstOrFail();

        $parameters = [
            'type' => EventTypeEnum::GAME->value,
            'venue_id' => $venue->id,
            'venue_court_id' => $court->id,
        ];

        $this->get(route('events.wizard', $parameters))
            ->assertRedirect(route('events.create', $parameters));

        $this->get(route('events.create', $parameters))
            ->assertOk()
            ->assertSee('name="venue_id"', false)
            ->assertSee('value="'.$venue->id.'" selected', false)
            ->assertSee('value="'.$court->id.'" data-venue-id="'.$venue->id.'"', false);
    }

    public function test_wizard_without_venue_keeps_creation_flow_unbound(): void
    {
        $this->signIn();

        $parameters = ['type' => EventTypeEnum::GAME->value];

        $this->get(route('events.wizard', $parameters))
            ->assertRedirect(route('events.create', $parameters));

        $this->get(route('events.create', $parameters))
            ->assertOk()
            ->assertSee('Выберите площадку');
    }

    public function test_pre_request_is_opt_in_and_never_implied_by_opening_create_form(): void
    {
        $this->signIn();

        $response = $this->get(route('events.create'))
            ->assertOk()
            ->assertSee('name="send_pre_request" value="0"', false)
            ->assertSee('не занимает слот и не гарантирует аренду');

        // A pre-request must not be sent merely because the organizer opened
        // the creation form. Explicitly checking the box is required.
        $this->assertSame(1, preg_match(
            '/<input\\s+type="checkbox"\\s+name="send_pre_request"\\s+value="1"\\s*>/',
            $response->getContent(),
        ));
    }

    public function test_wizard_and_create_reject_court_from_another_venue(): void
    {
        $this->signIn();
        $venue = Venue::factory()->create();
        $otherVenue = Venue::factory()->create();
        $otherCourt = $otherVenue->primaryCourt()->firstOrFail();

        foreach (['events.wizard', 'events.create'] as $route) {
            $this->get(route($route, [
                'venue_id' => $venue->id,
                'venue_court_id' => $otherCourt->id,
            ]))->assertSessionHasErrors('venue_court_id');
        }
    }

    public function test_create_without_venue_shows_a_selection_instead_of_inventing_one(): void
    {
        $this->signIn();
        $this->get(route('events.create'))
            ->assertOk()
            ->assertSee('name="venue_id"', false)
            ->assertSee('Выберите площадку');
    }
    public function test_court_cannot_be_preselected_without_its_parent_venue(): void
    {
        $this->signIn();
        $court = Venue::factory()->create()->primaryCourt()->firstOrFail();

        foreach (['events.wizard', 'events.create'] as $route) {
            $this->get(route($route, ['venue_court_id' => $court->id]))
                ->assertSessionHasErrors('venue_id');
        }
    }

    public function test_nonexistent_venue_cannot_be_preselected(): void
    {
        $this->signIn();

        foreach (['events.wizard', 'events.create'] as $route) {
            $this->get(route($route, ['venue_id' => 2147483647]))
                ->assertSessionHasErrors('venue_id');
        }
    }

}
