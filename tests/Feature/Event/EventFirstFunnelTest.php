<?php

namespace Tests\Feature\Event;

use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
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

    private function verifiedOrganizer(): User
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'event-organizer-'.$user->id.'@example.test',
            'is_primary' => true,
            'is_public' => false,
            'verified_at' => now(),
        ]);

        return $user;
    }

    public function test_planning_team_search_only_returns_active_permanent_visible_teams(): void
    {
        $user = $this->verifiedOrganizer();
        $actor = \App\Modules\Identity\Domain\Models\Actor::factory()->create(['user_id' => $user->id]);
        $create = fn (string $suffix, string $status) => \App\Modules\Team\Domain\Models\Team::query()->create([
            'created_by_actor_id' => $actor->id,
            'name' => 'Planning Rockets '.$suffix,
            'alias' => 'planning-rockets-'.$suffix,
            'status' => $status,
        ]);
        $first = $create('alpha', 'active');
        $second = $create('beta', 'active');
        $create('draft', 'draft');
        $create('blocked', 'blocked');
        $create('archived', 'archived');
        $create('deleted', 'active')->delete();

        $response = $this->getJson(route('events.funnel.teams', ['q' => 'Rockets']))
            ->assertOk()->assertJsonCount(2, 'teams');
        $this->assertSame([$first->id, $second->id], collect($response->json('teams'))->pluck('id')->all());
        $response->assertJsonStructure(['teams' => [['id', 'name', 'logo_url']]]);
        $this->getJson(route('events.funnel.teams', ['q' => 'Rockets', 'exclude_id' => $first->id]))
            ->assertOk()->assertJsonCount(1, 'teams')->assertJsonPath('teams.0.id', $second->id);
        $this->getJson(route('events.funnel.teams', ['q' => 'R']))->assertUnprocessable();
    }

    public function test_contact_required_even_for_confirmed_account_and_pending_contact_can_resume(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $this->verifiedOrganizer();
        $venue = $this->venue();
        $payload = $this->payload($venue);

        $this->actingAs($user)->postJson(route('events.funnel.store'), $payload)
            ->assertUnprocessable()->assertJsonPath('code', 'CONTACT_REQUIRED')
            ->assertJsonPath('pending_contact', null);
        $contact = $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'pending-event-organizer@example.test',
            'is_primary' => true,
            'is_public' => false,
        ]);
        $this->postJson(route('events.funnel.store'), $payload)
            ->assertUnprocessable()->assertJsonPath('code', 'CONTACT_REQUIRED')
            ->assertJsonPath('pending_contact.id', $contact->id);
        $this->assertDatabaseCount('events', 0);

        $contact->update(['verified_at' => now()]);
        $user->forceFill(['status' => UserStatusEnum::UNCONFIRMED])->save();
        $this->actingAs($user)->postJson(route('events.funnel.store'), $payload)
            ->assertCreated();
        $this->assertDatabaseCount('events', 1);
    }

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

    public function test_selected_telegram_groups_are_saved_for_quick_creation_and_edit(): void
    {
        $organizer = $this->verifiedOrganizer();
        $venue = $this->venue();
        $chats = collect([123456701, 123456702, 123456703])->map(fn ($id) =>
            \App\Modules\Telegram\Domain\Models\TelegramChat::query()->create([
                'telegram_chat_id' => -$id,
                'title' => 'Event chat '.$id,
                'type' => 'supergroup',
                'is_active' => true,
                'publishes_events' => true,
                'publishes_coordination' => false,
            ]));
        $payload = $this->payload($venue, $this->slots());
        $this->actingAs($organizer)->postJson(route('events.funnel.store'), [
            ...$payload,
            'publish_to_telegram' => true,
            'telegram_chat_ids' => $chats->take(2)->pluck('id')->all(),
        ])->assertCreated();
        $event = Event::query()->sole();
        $this->assertCount(2, $event->telegramPublications()->get());

        $this->put(route('events.update', $event->routeIdentifier()), [
            'title' => $event->title,
            'type' => $event->type->value,
            'visibility' => 'public',
            'publish_to_telegram' => true,
            'telegram_chat_ids' => [$chats->last()->id],
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertCount(3, $event->telegramPublications()->get());
    }

    public function test_retired_game_training_type_cannot_create_new_event(): void
    {
        $user = $this->verifiedOrganizer();
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
        $user = $this->verifiedOrganizer();
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
        $user = $this->verifiedOrganizer();
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
        $user = $this->verifiedOrganizer();
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
        $user = $this->verifiedOrganizer();
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
        $user = $this->verifiedOrganizer();
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

    public function test_explicit_game_list_overrides_legacy_default_and_can_be_empty(): void
    {
        $user = $this->verifiedOrganizer();
        $input = [...$this->payload($this->venue()), 'type' => 'game', 'games' => []];
        $this->actingAs($user)->postJson(route('events.funnel.store'), $input)->assertCreated();
        $event = Event::query()->sole();
        $this->assertNull($event->primary_game_id);
        $this->assertSame(0, $event->games()->count());
    }

    public function test_multiple_planned_games_keep_first_primary_and_do_not_invite_teams(): void
    {
        $user = $this->verifiedOrganizer();
        $actor = \App\Modules\Identity\Domain\Models\Actor::factory()->create(['user_id' => $user->id]);
        $team = fn (string $suffix) => \App\Modules\Team\Domain\Models\Team::query()->create([
            'created_by_actor_id' => $actor->id,
            'name' => 'Planned '.$suffix,
            'alias' => 'planned-'.$suffix,
            'status' => 'active',
        ]);
        $a = $team('alpha');
        $b = $team('beta');
        $input = [...$this->payload($this->venue()), 'type' => 'game', 'games' => [
            ['title' => 'Первый матч', 'format' => 'streetball_3x3'],
            ['title' => 'Второй матч', 'format' => 'basketball_5x5',
                'recruitment_mode' => 'preformed_teams', 'team_a_id' => $a->id, 'team_b_id' => $b->id,
                'timing_mode' => 'periods', 'periods_count' => 2],
        ]];
        $this->actingAs($user)->postJson(route('events.funnel.store'), $input)->assertCreated();
        $this->postJson(route('events.funnel.store'), $input)->assertCreated();
        $event = Event::query()->sole();
        $games = $event->games()->orderBy('id')->get();
        $this->assertCount(2, $games);
        $this->assertSame($games[0]->id, $event->primary_game_id);
        $this->assertSame('Первый матч', $games[0]->title);
        $this->assertSame(3, (int) $games[0]->side_a_size);
        $this->assertSame(0, $games[0]->periods()->count());
        $this->assertSame($a->id, (int) $games[1]->planned_team_a_id);
        $this->assertSame($b->id, (int) $games[1]->planned_team_b_id);
        $this->assertSame(2, $games[1]->periods()->count());
        $this->assertSame(0, $games[1]->admissions()->count());
        $this->assertSame(0, $games[1]->sides()->count());
    }

    public function test_invalid_planned_team_selection_rolls_back_entire_event(): void
    {
        $user = $this->verifiedOrganizer();
        $input = [...$this->payload($this->venue()), 'type' => 'game', 'games' => [
            ['title' => 'Первый матч', 'format' => 'streetball_3x3'],
            ['title' => 'Второй матч', 'format' => 'basketball_5x5', 'recruitment_mode' => 'preformed_teams'],
        ]];
        $this->actingAs($user)->postJson(route('events.funnel.store'), $input)
            ->assertUnprocessable()->assertJsonValidationErrors(['games.1.team_a_id', 'games.1.team_b_id']);
        $this->assertDatabaseCount('events', 0);
        $this->assertDatabaseCount('games', 0);
    }

    public function test_duplicate_time_options_are_rejected(): void
    {
        $user = $this->verifiedOrganizer();
        $venue = $this->venue();
        $slot = $this->slots()[0];

        $this->actingAs($user)->postJson(route('events.funnel.store'),
            $this->payload($venue, [$slot, $slot]))
            ->assertUnprocessable()->assertJsonValidationErrors('slots');
        $this->assertDatabaseCount('events', 0);
    }
    public function test_organizer_role_is_opt_in_preserves_other_roles_and_is_idempotent(): void
    {
        $user = $this->verifiedOrganizer();
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

        $user = $this->verifiedOrganizer();
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

    public function test_organizer_attendance_is_default_but_optional_and_can_change_without_losing_role(): void
    {
        $organizer = $this->verifiedOrganizer();
        $venue = $this->venue();
        $payload = $this->payload($venue, [$this->slots()[0]]);
        $payload['max_participants'] = 2;
        $payload['organizer_participates'] = true;
        $this->actingAs($organizer)->postJson(route('events.funnel.store'), $payload)->assertCreated();
        $event = Event::query()->sole();
        $this->assertSame('confirmed', $event->participants()->sole()->status->value);
        $initialWall = route('events.wall.index', $event->routeIdentifier());
        $this->getJson($initialWall)->assertOk()
            ->assertJsonCount(1, 'entries')
            ->assertJsonPath('entries.0.kind', 'event_rsvp_changed')
            ->assertJsonPath('entries.0.system', true);
        $this->postJson(route('events.funnel.store'), $payload)->assertCreated();
        $this->getJson($initialWall)->assertOk()->assertJsonCount(1, 'entries');

        $payload = $this->payload($venue, [$this->slots()[1]]);
        $payload['max_participants'] = 2;
        $payload['organizer_participates'] = false;
        $this->postJson(route('events.funnel.store'), $payload)->assertCreated();
        $event = Event::query()->latest('id')->firstOrFail();
        $url = route('events.participation', $event->routeIdentifier());
        $wall = route('events.wall.index', [$event->routeIdentifier(), 'include_roster' => 1]);
        $this->assertSame('left', $event->participants()->sole()->status->value);
        $this->assertSame([], $event->participants()->sole()->participation_roles);
        $this->getJson($wall)->assertOk()->assertJsonPath('can_post', true)
            ->assertJsonPath('roster.members.0.organizer', true)
            ->assertJsonPath('roster.members.0.status', 'left')
            ->assertJsonPath('counts.confirmed', 0)
            ->assertJsonCount(0, 'entries');

        $first = $this->verifiedOrganizer();
        $second = $this->verifiedOrganizer();
        foreach ([$first, $second] as $player) {
            $this->actingAs($player)->patchJson($url, ['status' => 'confirmed'])->assertOk();
        }
        $this->actingAs($organizer)->patchJson($url, ['status' => 'confirmed'])->assertStatus(409);
        $this->actingAs($first)->patchJson($url, ['status' => 'left'])->assertOk();

        $this->actingAs($organizer)->patchJson($url, ['status' => 'confirmed', 'include_roster' => true])
            ->assertOk()->assertJsonPath('counts.confirmed', 2)
            ->assertJsonPath('roster.members.0.organizer', true)
            ->assertJsonPath('roster.members.0.status', 'confirmed');
        $this->patchJson($url, ['status' => 'left', 'include_roster' => true])
            ->assertOk()->assertJsonPath('counts.confirmed', 1)
            ->assertJsonPath('roster.members.0.organizer', true)
            ->assertJsonPath('roster.members.0.status', 'left');
        $this->getJson($wall)->assertOk()->assertJsonPath('can_post', true);
        $this->assertSame('organizer', $event->participants()->where('user_id', $organizer->id)->sole()->role->value);
        $this->assertSame($organizer->id, $event->refresh()->organizerActor->user_id);
    }
}
