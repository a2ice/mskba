<?php

namespace Tests\Feature\Event;

use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Application\UseCases\UpdateUserParticipationRolesHandler;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EventFirstFunnelTest extends TestCase
{
    use RefreshDatabase;

    private function venue(): Venue
    {
        return Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED,
            'is_published' => true,
        ]);
    }

    private function payload(Venue $venue, array $slots = [], string $mode = 'alternatives'): array
    {
        return [
            'event_request_id' => (string) Str::uuid(),
            'venue_id' => $venue->id,
            'type' => 'training',
            'visibility' => 'public',
            'title' => 'Утренняя тренировка',
            'slots' => $slots,
            'schedule_mode' => $mode,
        ];
    }

    private function slots(): array
    {
        $day = CarbonImmutable::now('Europe/Moscow')->addDays(4);
        return [
            ['starts_at' => $day->setTime(19, 0)->format('Y-m-d\TH:i'), 'duration_minutes' => 90],
            ['starts_at' => $day->addDay()->setTime(18, 30)->format('Y-m-d\TH:i'), 'duration_minutes' => 60],
        ];
    }

    public function test_retired_game_training_type_cannot_create_new_event(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $venue = $this->venue();
        $payload = $this->payload($venue);
        $payload['type'] = 'game_training';

        $this->actingAs($user)
            ->postJson(route('events.funnel.store'), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('type');

        $this->assertDatabaseCount('events', 0);
    }

    public function test_guest_cannot_create_event_but_can_fill_public_wizard(): void
    {
        $venue = $this->venue();
        $this->postJson(route('events.funnel.store'), $this->payload($venue))
            ->assertUnauthorized();
        $this->assertDatabaseCount('events', 0);
    }

    public function test_without_schedule_creates_only_an_unpublished_unscheduled_draft(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $venue = $this->venue();

        $this->actingAs($user)->postJson(route('events.funnel.store'), $this->payload($venue))
            ->assertCreated()->assertJsonPath('created_count', 1);
        $event = Event::query()->sole();

        $this->assertSame(EventStatusEnum::DRAFT, $event->status);
        $this->assertNull($event->starts_at);
        $this->assertNull($event->ends_at);
        $this->assertTrue($event->booking_deferred);
        $this->assertDatabaseCount('venue_bookings', 0);
        $this->assertDatabaseCount('event_venue_interests', 0);
        $this->actingAs($user)->get(route('events.show', $event->routeIdentifier()))
            ->assertOk()->assertSee('Мероприятие сохранено как черновик');
    }

    public function test_two_alternative_slots_create_one_event_and_one_nonbinding_interest(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $venue = $this->venue();

        $this->actingAs($user)->postJson(route('events.funnel.store', []),
            $this->payload($venue, $this->slots()))
            ->assertCreated()->assertJsonPath('created_count', 1);

        $event = Event::query()->sole();
        $this->assertSame(EventStatusEnum::PUBLISHED, $event->status);
        $this->assertCount(2, $event->proposed_time_options);
        $this->assertTrue($event->booking_deferred);
        $this->assertDatabaseCount('event_venue_interests', 1);
        $this->assertDatabaseCount('venue_bookings', 0);
    }

    public function test_separate_mode_creates_distinct_events_without_bookings_and_retries_are_idempotent(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $venue = $this->venue();
        $payload = $this->payload($venue, $this->slots(), 'separate');

        $this->actingAs($user)->postJson(route('events.funnel.store'), $payload)
            ->assertCreated()->assertJsonPath('created_count', 2);
        $this->assertDatabaseCount('events', 2);
        $this->assertDatabaseCount('event_venue_interests', 2);
        $this->assertDatabaseCount('venue_bookings', 0);
        $this->actingAs($user)->postJson(route('events.funnel.store'), $payload)
            ->assertCreated()->assertJsonPath('created_count', 2);
        $this->assertDatabaseCount('events', 2);
        $this->assertDatabaseCount('event_venue_interests', 2);
    }

    public function test_game_type_is_an_event_container_and_can_have_no_game(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $venue = $this->venue();
        $input = $this->payload($venue, $this->slots());
        $input['type'] = 'game';
        $input['create_game'] = false;

        $this->actingAs($user)->postJson(route('events.funnel.store'), $input)
            ->assertCreated()->assertJsonPath('created_count', 1);

        $event = Event::query()->sole();
        $this->assertSame('game', $event->type->value);
        $this->assertNull($event->primary_game_id);
        $this->assertSame(0, $event->games()->count());
        $this->actingAs($user)->get(route('events.show', $event->routeIdentifier()))
            ->assertOk()->assertSee($event->title);
    }

    public function test_game_type_can_create_an_initial_child_game_by_default(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $venue = $this->venue();
        $input = $this->payload($venue, $this->slots());
        $input['type'] = 'game';
        $input['game_format'] = 'streetball_3x3';
        $input['game_recruitment_mode'] = 'individual_draft';
        $input['side_a_size'] = 3;
        $input['side_b_size'] = 3;
        $input['scoring_type'] = 'streetball';
        $input['timing_mode'] = 'whole_game';
        // Checkbox is enabled by default: omitting the optional flag must not
        // change the original quick-create behavior.
        $this->actingAs($user)->postJson(route('events.funnel.store'), $input)
            ->assertCreated()->assertJsonPath('created_count', 1);

        $event = Event::query()->sole();
        $this->assertNotNull($event->primary_game_id);
        $this->assertSame(1, $event->games()->count());
        $this->assertDatabaseCount('venue_bookings', 0);
    }

    public function test_duplicate_time_options_are_rejected(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $venue = $this->venue();
        $slot = $this->slots()[0];

        $this->actingAs($user)->postJson(route('events.funnel.store'),
            $this->payload($venue, [$slot, $slot]))
            ->assertUnprocessable()->assertJsonValidationErrors('slots');
        $this->assertDatabaseCount('events', 0);
    }
    public function test_organizer_role_is_opt_in_preserves_other_roles_and_is_idempotent(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        app(UpdateUserParticipationRolesHandler::class)->updateOne($user, UserParticipationRoleEnum::PLAYER, true);
        $venue = $this->venue();
        $input = $this->payload($venue);
        $input['add_organizer_role'] = false;
        $this->actingAs($user)->postJson(route('events.funnel.store'), $input)->assertCreated();
        $this->assertFalse($user->participationRoles()->where('role', UserParticipationRoleEnum::ORGANIZER->value)->exists());

        $input = $this->payload($venue);
        $input['add_organizer_role'] = true;
        $invalid = [...$input, 'venue_id' => 0];
        $this->actingAs($user)->postJson(route('events.funnel.store'), $invalid)->assertUnprocessable();
        $this->assertFalse($user->participationRoles()->where('role', UserParticipationRoleEnum::ORGANIZER->value)->exists());
        $this->actingAs($user)->postJson(route('events.funnel.store'), $input)->assertCreated();
        $this->actingAs($user)->postJson(route('events.funnel.store'), $input)->assertCreated();
        $this->assertSame(1, $user->participationRoles()->where('role', UserParticipationRoleEnum::ORGANIZER->value)->count());
        $this->assertTrue($user->participationRoles()->where('role', UserParticipationRoleEnum::PLAYER->value)->exists());
    }

    public function test_legacy_postgres_constraint_is_upgraded_without_losing_existing_roles(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL-specific legacy CHECK constraint.');
        }

        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        app(UpdateUserParticipationRolesHandler::class)->updateOne($user, UserParticipationRoleEnum::PLAYER, true);

        DB::statement('ALTER TABLE user_participation_roles DROP CONSTRAINT IF EXISTS user_participation_roles_role_check');
        DB::statement("ALTER TABLE user_participation_roles ADD CONSTRAINT user_participation_roles_role_check CHECK (role IN ('player', 'coach', 'referee', 'statistician', 'media', 'venue_related'))");

        $migration = require database_path('migrations/2026_11_20_160000_allow_organizer_participation_role_on_existing_postgres.php');
        $migration->up();

        $input = $this->payload($this->venue(), [$this->slots()[0]]);
        $input['add_organizer_role'] = true;
        $this->actingAs($user)->postJson(route('events.funnel.store'), $input)->assertCreated();
        $this->assertSame(EventStatusEnum::PUBLISHED, Event::query()->sole()->status);
        $this->assertTrue($user->participationRoles()->where('role', UserParticipationRoleEnum::ORGANIZER->value)->exists());
        $this->assertTrue($user->participationRoles()->where('role', UserParticipationRoleEnum::PLAYER->value)->exists());
        $this->assertDatabaseCount('event_venue_interests', 1);
        $this->assertDatabaseCount('venue_bookings', 0);
    }
}
