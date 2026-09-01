<?php

namespace Tests\Feature\Event;

use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\VenueBooking;
use App\Modules\Identity\Domain\Enums\ActorTypeEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Domain\Enums\TeamStatusEnum;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class EventCreationWizardTest extends TestCase
{
    use RefreshDatabase;

    public function test_wizard_requires_authentication(): void
    {
        $this->get(route('events.wizard'))
            ->assertRedirect(route('login'));
    }

    public function test_wizard_renders_selected_entry_type_without_replacing_legacy_create_form(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        Actor::factory()->create([
            'type' => ActorTypeEnum::USER->value,
            'user_id' => $user->id,
        ]);

        $this->actingAs($user)
            ->get(route('events.wizard', ['type' => EventTypeEnum::TRAINING->value]))
            ->assertOk()
            ->assertSee('data-event-wizard', false)
            ->assertSee('Создать мероприятие')
            ->assertSee('value="training"', false)
            ->assertSee(route('events.create', ['type' => EventTypeEnum::TRAINING->value]), false);

        $this->actingAs($user)
            ->get(route('events.create', ['type' => EventTypeEnum::TRAINING->value]))
            ->assertOk()
            ->assertDontSee('data-event-wizard', false);
    }

    public function test_team_picker_exposes_invitable_teams_and_own_opted_out_team_but_hides_unrelated_opted_out_team(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $actor = Actor::factory()->create([
            'type' => ActorTypeEnum::USER->value,
            'user_id' => $user->id,
        ]);
        $otherUser = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $otherActor = Actor::factory()->create([
            'type' => ActorTypeEnum::USER->value,
            'user_id' => $otherUser->id,
        ]);

        $publicTeam = Team::query()->create([
            'created_by_actor_id' => $otherActor->id,
            'name' => 'Public Rockets',
            'alias' => 'public-rockets',
            'status' => TeamStatusEnum::ACTIVE,
            'accepts_competition_invitations' => true,
        ]);
        $hiddenTeam = Team::query()->create([
            'created_by_actor_id' => $otherActor->id,
            'name' => 'Private Rockets',
            'alias' => 'private-rockets',
            'status' => TeamStatusEnum::ACTIVE,
            'accepts_competition_invitations' => false,
        ]);
        $ownTeam = Team::query()->create([
            'created_by_actor_id' => $actor->id,
            'name' => 'Own Rockets',
            'alias' => 'own-rockets',
            'status' => TeamStatusEnum::ACTIVE,
            'accepts_competition_invitations' => false,
        ]);

        $response = $this->actingAs($user)
            ->getJson(route('events.wizard.teams'))
            ->assertOk();

        $ids = collect($response->json('teams'))->pluck('id');
        $this->assertTrue($ids->contains($publicTeam->id));
        $this->assertTrue($ids->contains($ownTeam->id));
        $this->assertFalse($ids->contains($hiddenTeam->id));

        $ownPayload = collect($response->json('teams'))->firstWhere('id', $ownTeam->id);
        $this->assertTrue($ownPayload['manageable']);
        $this->assertFalse($ownPayload['accepts_invitations']);
    }

    public function test_half_court_discovery_keeps_two_hoop_venue_when_only_opposite_half_is_free(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        Actor::factory()->create([
            'type' => ActorTypeEnum::USER->value,
            'user_id' => $user->id,
        ]);
        $otherUser = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $otherActor = Actor::factory()->create([
            'type' => ActorTypeEnum::USER->value,
            'user_id' => $otherUser->id,
        ]);
        $venue = Venue::factory()->create([
            'name' => 'Две половины для wizard',
            'status' => VenueStatusEnum::CONFIRMED->value,
            'operational_status' => VenueOperationalStatusEnum::ACTIVE->value,
        ]);
        $venue->characteristics()->create(['hoops_count' => 2]);

        $start = CarbonImmutable::now('Europe/Moscow')->addDays(2)->startOfHour();
        $occupiedEvent = Event::factory()->create([
            'venue_id' => $venue->id,
            'organizer_actor_id' => $otherActor->id,
        ]);
        VenueBooking::query()->create([
            'venue_id' => $venue->id,
            'event_id' => $occupiedEvent->id,
            'created_by_actor_id' => $otherActor->id,
            'status' => VenueBookingStatusEnum::CONFIRMED->value,
            'scope' => VenueBookingScopeEnum::HALF_A->value,
            'starts_at' => $start,
            'ends_at' => $start->addHour(),
        ]);

        $parameters = [
            'query' => 'Две половины',
            'confirmed_only' => '1',
            'operational_status' => VenueOperationalStatusEnum::ACTIVE->value,
            'starts_at' => $start->format('Y-m-d\TH:i'),
            'duration_minutes' => 60,
            'booking_scope' => VenueBookingScopeEnum::WHOLE->value,
        ];

        $this->actingAs($user)
            ->getJson(route('events.wizard.venues', $parameters))
            ->assertOk()
            ->assertJsonCount(1, 'venues')
            ->assertJsonPath('venues.0.id', $venue->id)
            ->assertJsonPath('venues.0.available_scopes', [VenueBookingScopeEnum::HALF_B->value]);

        $this->actingAs($user)
            ->getJson(route('events.wizard.venues', [
                ...$parameters,
                'query' => '',
                'venue_id' => $venue->id,
                'discover_scopes' => '1',
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'venues')
            ->assertJsonPath('venues.0.id', $venue->id)
            ->assertJsonPath('venues.0.available_scopes', [VenueBookingScopeEnum::HALF_B->value]);

        $this->actingAs($user)
            ->getJson(route('events.wizard.venues', [
                ...$parameters,
                'query' => '',
                'venue_id' => $venue->id,
                'booking_scope' => VenueBookingScopeEnum::HALF_A->value,
            ]))
            ->assertOk()
            ->assertJsonCount(0, 'venues');

        $this->actingAs($user)
            ->getJson(route('events.wizard.venues', [
                ...$parameters,
                'query' => '',
                'venue_id' => $venue->id,
                'booking_scope' => VenueBookingScopeEnum::HALF_B->value,
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'venues')
            ->assertJsonPath('venues.0.id', $venue->id)
            ->assertJsonPath('venues.0.available_scopes', [VenueBookingScopeEnum::HALF_B->value]);
    }

    public function test_wizard_style_3x3_submission_books_the_free_half(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        Actor::factory()->create([
            'type' => ActorTypeEnum::USER->value,
            'user_id' => $user->id,
        ]);
        $otherUser = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $otherActor = Actor::factory()->create([
            'type' => ActorTypeEnum::USER->value,
            'user_id' => $otherUser->id,
        ]);
        $venue = Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED->value,
            'operational_status' => VenueOperationalStatusEnum::ACTIVE->value,
            'requires_payment' => false,
            'requires_booking_approval' => false,
        ]);
        $venue->characteristics()->create(['hoops_count' => 2]);
        $start = CarbonImmutable::now('Europe/Moscow')->addDays(3)->startOfHour();
        $occupiedEvent = Event::factory()->create([
            'venue_id' => $venue->id,
            'organizer_actor_id' => $otherActor->id,
        ]);
        VenueBooking::query()->create([
            'venue_id' => $venue->id,
            'event_id' => $occupiedEvent->id,
            'created_by_actor_id' => $otherActor->id,
            'status' => VenueBookingStatusEnum::CONFIRMED->value,
            'scope' => VenueBookingScopeEnum::HALF_A->value,
            'starts_at' => $start,
            'ends_at' => $start->addHour(),
        ]);

        $response = $this->actingAs($user)
            ->from(route('events.wizard'))
            ->post(route('events.store'), [
                'venue_id' => $venue->id,
                'title' => 'Wizard 3x3',
                'type' => EventTypeEnum::GAME->value,
                'visibility' => 'public',
                'starts_at' => $start->format('Y-m-d\TH:i'),
                'duration_minutes' => 60,
                'booking_scope' => VenueBookingScopeEnum::HALF_B->value,
                'game_format' => 'streetball_3x3',
                'game_recruitment_mode' => 'preformed_teams',
                'game_accepts_applications' => true,
                'side_a_size' => 3,
                'side_b_size' => 3,
                'scoring_type' => 'streetball',
                'timing_mode' => 'whole_game',
            ]);

        $event = Event::query()->where('title', 'Wizard 3x3')->firstOrFail();
        $response->assertRedirect(route('events.show', $event->routeIdentifier()));
        $this->assertSame(VenueBookingScopeEnum::HALF_B, $event->booking->scope);
        $this->assertSame(EventTypeEnum::GAME, $event->type);

        $game = $event->primaryGame()->firstOrFail();
        $this->assertSame('streetball_3x3', $game->format->value);
        $this->assertSame(3, $game->side_a_size);
        $this->assertSame(3, $game->side_b_size);
        $this->assertSame('preformed_teams', $game->recruitment_mode->value);
    }

    public function test_game_training_can_book_a_half_independently_of_game_format(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        Actor::factory()->create([
            'type' => ActorTypeEnum::USER->value,
            'user_id' => $user->id,
        ]);
        $venue = Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED->value,
            'operational_status' => VenueOperationalStatusEnum::ACTIVE->value,
            'requires_payment' => false,
            'requires_booking_approval' => false,
        ]);
        $venue->characteristics()->create(['hoops_count' => 2]);
        $start = CarbonImmutable::now('Europe/Moscow')->addDays(3)->startOfHour();

        $response = $this->actingAs($user)
            ->from(route('events.wizard'))
            ->post(route('events.store'), [
                'venue_id' => $venue->id,
                'title' => 'Игровая тренировка на половине',
                'type' => EventTypeEnum::GAME_TRAINING->value,
                'visibility' => 'public',
                'starts_at' => $start->format('Y-m-d\TH:i'),
                'duration_minutes' => 60,
                'booking_scope' => VenueBookingScopeEnum::HALF_A->value,
            ]);

        $event = Event::query()->where('title', 'Игровая тренировка на половине')->firstOrFail();
        $response->assertRedirect(route('events.show', $event->routeIdentifier()));
        $this->assertSame(EventTypeEnum::GAME_TRAINING, $event->type);
        $this->assertSame(VenueBookingScopeEnum::HALF_A, $event->booking->scope);
    }

    public function test_wizard_preserves_selected_venue_id_after_server_validation_redirect(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        Actor::factory()->create([
            'type' => ActorTypeEnum::USER->value,
            'user_id' => $user->id,
        ]);
        $venue = Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED->value,
            'operational_status' => VenueOperationalStatusEnum::ACTIVE->value,
        ]);

        $this->actingAs($user)
            ->withSession(['_old_input' => ['venue_id' => $venue->id]])
            ->get(route('events.wizard'))
            ->assertOk()
            ->assertSee('name="venue_id"', false)
            ->assertSee('value="'.$venue->id.'"', false);
    }
}
