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

    public function test_unconfigured_game_has_no_invented_sports_parameters(): void
    {
        $user = $this->verifiedOrganizer();
        $input = [...$this->payload($this->venue()), 'type' => 'game', 'games' => [
            ['title' => 'Игра', 'format' => null],
        ]];
        $this->actingAs($user)->postJson(route('events.funnel.store'), $input)->assertCreated();
        $event = Event::query()->sole();
        $game = $event->games()->sole();
        $this->assertSame($game->id, $event->primary_game_id);
        foreach (['format', 'side_a_size', 'side_b_size', 'scoring_type', 'timing_mode', 'periods_count', 'recruitment_mode'] as $field) {
            $this->assertNull($game->{$field});
        }
        $this->assertFalse($game->hasSportConfiguration());
        $this->assertFalse($game->acceptsAdmissions());
        $this->assertSame('Не определен', $game->formatLabel());
        $this->assertSame(0, $game->periods()->count());
        $this->assertSame(0, $game->sides()->count());
        $this->get(route('events.show', $event->routeIdentifier()))->assertOk();
        $this->postJson(route('events.games.start', [$event->routeIdentifier(), $game->id]))
            ->assertUnprocessable();
        $this->assertNull($game->fresh()->actual_started_at);
    }

    public function test_unconfigured_game_rejects_stale_sport_fields(): void
    {
        $user = $this->verifiedOrganizer();
        $input = [...$this->payload($this->venue()), 'type' => 'game', 'games' => [
            ['title' => 'Игра', 'format' => null, 'scoring_type' => 'basketball'],
        ]];
        $this->actingAs($user)->postJson(route('events.funnel.store'), $input)
            ->assertUnprocessable()->assertJsonValidationErrors(['games.0.scoring_type']);
        $this->assertDatabaseCount('events', 0);
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

    public function test_planned_game_management_configures_and_removes_games_without_losing_event(): void
    {
        $user = $this->verifiedOrganizer();
        $input = [...$this->payload($this->venue()), 'type' => 'game', 'games' => []];
        $this->actingAs($user)->postJson(route('events.funnel.store'), $input)->assertCreated();
        $event = Event::query()->sole();
        $firstId = $this->postJson(route('events.planned-games.store', $event->routeIdentifier()), [
            'title' => 'Игра', 'format' => null,
        ])->assertCreated()->json('game_id');
        $secondId = $this->postJson(route('events.planned-games.store', $event->routeIdentifier()), [
            'title' => 'Вторая игра', 'format' => 'streetball_3x3',
        ])->assertCreated()->json('game_id');
        $this->assertSame((int) $firstId, (int) $event->fresh()->primary_game_id);
        $this->putJson(route('events.planned-games.update', [$event->routeIdentifier(), $firstId]), [
            'title' => 'Матч', 'format' => 'basketball_5x5', 'timing_mode' => 'periods', 'periods_count' => 2,
        ])->assertOk();
        $first = $event->games()->findOrFail($firstId);
        $this->assertTrue($first->hasSportConfiguration());
        $this->assertSame(2, $first->periods()->count());
        $this->assertSame('Матч', $first->title);

        $this->actingAs($this->verifiedOrganizer())
            ->deleteJson(route('events.planned-games.destroy', [$event->routeIdentifier(), $firstId]))->assertForbidden();
        $this->actingAs($user)
            ->deleteJson(route('events.planned-games.destroy', [$event->routeIdentifier(), $firstId]))->assertOk();
        $this->assertSoftDeleted('games', ['id' => $firstId]);
        $this->assertSame((int) $secondId, (int) $event->fresh()->primary_game_id);
        $this->deleteJson(route('events.planned-games.destroy', [$event->routeIdentifier(), $secondId]))->assertOk();
        $this->assertNull($event->fresh()->primary_game_id);
        $this->assertSame(0, $event->games()->count());
        $this->assertDatabaseCount('events', 1);
    }

    public function test_planned_game_management_does_not_modify_started_or_foreign_games(): void
    {
        $user = $this->verifiedOrganizer();
        $venue = $this->venue();
        $input = [...$this->payload($venue), 'type' => 'game', 'games' => [
            ['title' => 'Матч', 'format' => 'basketball_5x5'],
        ]];
        $this->actingAs($user)->postJson(route('events.funnel.store'), $input)->assertCreated();
        $event = Event::query()->sole();
        $game = $event->games()->sole();
        $other = [...$this->payload($venue), 'type' => 'game', 'games' => []];
        $this->postJson(route('events.funnel.store'), $other)->assertCreated();
        $otherEvent = Event::query()->whereKeyNot($event->id)->sole();
        $this->deleteJson(route('events.planned-games.destroy', [$otherEvent->routeIdentifier(), $game->id]))->assertNotFound();

        $game->forceFill(['actual_started_at' => now(), 'status' => 'in_progress'])->save();
        $this->putJson(route('events.planned-games.update', [$event->routeIdentifier(), $game->id]), [
            'title' => 'Изменено', 'format' => null,
        ])->assertUnprocessable();
        $this->deleteJson(route('events.planned-games.destroy', [$event->routeIdentifier(), $game->id]))->assertUnprocessable();
        $this->assertSame('Матч', $game->fresh()->title);
        $this->assertNotNull($game->fresh()->actual_started_at);
    }

    public function test_planned_game_invitations_are_addressed_to_each_game_and_can_be_repeated(): void
    {
        $organizer = $this->verifiedOrganizer();
        $ownerA = $this->verifiedOrganizer();
        $ownerB = $this->verifiedOrganizer();
        $outsider = $this->verifiedOrganizer();
        $team = function (User $owner, string $suffix) {
            $actor = \App\Modules\Identity\Domain\Models\Actor::factory()->create(['user_id' => $owner->id]);

            return \App\Modules\Team\Domain\Models\Team::query()->create([
                'created_by_actor_id' => $actor->id,
                'name' => 'Invite '.$suffix,
                'alias' => 'invite-'.$suffix,
                'status' => 'active',
                'accepts_competition_invitations' => true,
            ]);
        };
        $a = $team($ownerA, 'a');
        $b = $team($ownerB, 'b');
        $c = $team($outsider, 'c');
        $player = $this->verifiedOrganizer();
        $otherPlayer = $this->verifiedOrganizer();
        $formerPlayer = $this->verifiedOrganizer();
        foreach ([[$player, $a, 'active'], [$otherPlayer, $a, 'active'], [$formerPlayer, $a, 'active']] as [$member, $teamModel, $status]) {
            $contract = \App\Modules\Contract\Domain\Models\Contract::query()->create([
                'number' => 'invite-test-'.$member->id,
                'family' => \App\Modules\Contract\Domain\Enums\ContractFamilyEnum::MEMBERSHIP,
                'name' => 'Игрок команды',
                'status' => $status,
                'assigned_by' => $member->id,
                'assigned_at' => now(),
                'assigner' => \App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum::SEEDER,
            ]);
            $teamModel->memberships()->create([
                'contract_id' => $contract->id, 'scope_type' => 'team', 'user_id' => $member->id,
                'access_level' => 'player', 'sport_roles' => ['player'],
                'invitation_status' => $member->id === $formerPlayer->id ? 'pending' : 'accepted',
            ]);
        }
        $input = [...$this->payload($this->venue(), [$this->slots()[0]]), 'type' => 'game', 'games' => [
            ['title' => 'Первая игра', 'format' => 'streetball_3x3'],
            ['title' => 'Вторая игра', 'format' => 'basketball_5x5',
                'recruitment_mode' => 'preformed_teams', 'team_a_id' => $a->id, 'team_b_id' => $b->id],
        ]];
        $this->actingAs($organizer)->postJson(route('events.funnel.store'), $input)->assertCreated();
        $event = Event::query()->sole();
        $game = $event->games()->orderByDesc('id')->firstOrFail();
        $this->assertNotEquals($game->id, $event->primary_game_id);
        $this->assertSame(0, $game->admissions()->count());
        $params = [$event->routeIdentifier(), $game->id];
        $invite = route('events.games.recruitment.invite', $params);
        $this->postJson($invite, ['team_id' => $c->id])->assertUnprocessable();
        $b->update(['accepts_competition_invitations' => false]);
        $this->postJson($invite, ['team_id' => $b->id])->assertUnprocessable();
        $b->update(['accepts_competition_invitations' => true]);
        $this->actingAs($outsider)->postJson($invite, ['team_id' => $a->id])->assertUnprocessable();
        $this->actingAs($organizer)->postJson($invite, ['team_id' => $a->id])->assertOk();
        $first = $game->admissions()->sole();
        $context = \App\Modules\Event\Domain\Models\GameInvitationThread::query()->sole();
        $threadId = $context->thread()->sole()->id;
        $state = route('account.notifications.threads.game-invitation.state', $context);
        $messages = route('account.notifications.threads.game-invitation.messages', $context);
        $this->assertSame($game->id, $context->game_id);
        $this->assertSame($a->id, $context->team_id);
        $this->getJson($state)->assertOk()->assertJsonPath('can_organize', true);
        $this->actingAs($ownerB)->getJson($state)->assertForbidden();
        $this->actingAs($outsider)->postJson($messages, ['body' => 'Не имею доступа'])->assertForbidden();
        $this->actingAs($organizer);
        $notice = \App\Modules\Notification\Domain\Models\UserNotification::query()
            ->where('user_id', $ownerA->id)
            ->where('payload->game_admission_id', $first->id)->sole();
        $this->assertSame($game->id, $notice->payload['game_id']);
        $this->assertSame(route('account.notifications.threads.game-invitation', $context), $notice->action_url);
        $this->assertSame($context->id, $notice->payload['game_invitation_thread_id']);
        $this->assertTrue($notice->isNew());
        $this->postJson($invite, ['team_id' => $a->id])->assertUnprocessable();
        $notify = route('account.notifications.threads.game-invitation.notify-teammates', [$context, $first->id]);
        $this->postJson($notify, ['recipients' => [$player->id]])->assertForbidden();
        $this->actingAs($player)->postJson($notify, ['recipients' => [$player->id]])->assertForbidden();
        $this->actingAs($ownerA)->postJson($notify, ['recipients' => [$player->id, $formerPlayer->id]])->assertUnprocessable();
        $this->assertDatabaseCount('game_invitation_recipients', 0);
        $this->postJson($notify, ['recipients' => [$player->id, $outsider->id]])->assertUnprocessable();
        $this->postJson($notify, ['recipients' => [$player->id], 'comment' => 'Кто сможет сыграть?'])->assertOk()->assertJsonPath('sent', 1);
        $this->postJson($notify, ['recipients' => [$player->id]])->assertOk()->assertJsonPath('sent', 0);
        $this->assertDatabaseCount('game_invitation_recipients', 1);
        $this->assertDatabaseHas('game_invitation_recipients', ['game_admission_id' => $first->id, 'user_id' => $player->id, 'comment' => 'Кто сможет сыграть?']);
        $this->assertDatabaseMissing('game_invitation_recipients', ['user_id' => $otherPlayer->id]);
        $playerNotice = \App\Modules\Notification\Domain\Models\UserNotification::query()
            ->where('user_id', $player->id)->whereNotNull('payload->game_invitation_recipient_id')->sole();
        $this->assertSame($game->id, $playerNotice->payload['game_id']);
        $this->assertStringContainsString('Кто сможет сыграть?', $playerNotice->body);
        $this->assertStringContainsString('Соперник: '.$b->name, $playerNotice->body);
        $this->actingAs($player)->getJson($state)->assertForbidden();
        $this->actingAs($ownerA);
        $this->assertSame('pending', $first->fresh()->status->value);
        $this->assertSame(0, $game->rosterEntries()->count());
        $respond = route('events.games.recruitment.respond', [...$params, $first->id]);
        $this->actingAs($outsider)->postJson($respond, ['decision' => 'accepted'])->assertUnprocessable();
        $this->actingAs($ownerA)->postJson($respond, ['decision' => 'declined'])->assertOk();
        $this->assertSame('declined', $first->fresh()->status->value);
        $this->assertFalse($notice->fresh()->isNew());
        $this->postJson($notify, ['recipients' => [$otherPlayer->id]])->assertUnprocessable();
        $this->getJson($state)->assertOk()->assertJsonPath('invitations.0.status', 'declined');
        $this->postJson($messages, ['body' => 'Пришли ещё раз приглашение'])->assertCreated();
        $this->assertSame('declined', $first->fresh()->status->value);
        $this->actingAs($organizer)->getJson($state)->assertOk()
            ->assertJsonPath('messages.0.body', 'Пришли ещё раз приглашение');
        $this->actingAs($organizer)->postJson($invite, ['team_id' => $a->id])->assertOk();
        $second = $game->admissions()->latest('id')->firstOrFail();
        $this->assertNotEquals($first->id, $second->id);
        $this->assertSame('pending', $second->status->value);
        $this->assertDatabaseCount('game_invitation_threads', 1);
        $this->assertSame($threadId, $context->thread()->sole()->id);
        $channel = app(\App\Modules\Notification\Infrastructure\Broadcasting\ConversationChannel::class);
        $this->assertTrue($channel->gameInvitation($ownerA, $context->id));
        $this->assertTrue($channel->gameInvitation($organizer, $context->id));
        $this->assertFalse($channel->gameInvitation($ownerB, $context->id));
        $this->assertFalse($channel->gameInvitation($player, $context->id));
        $broadcast = new \App\Modules\Notification\Infrastructure\Broadcasting\ConversationChangedBroadcast('game_invitation', $context->id);
        $this->assertSame('private-game-invitation-threads.'.$context->id, $broadcast->broadcastOn()->name);
        $this->assertSame('conversation.changed', $broadcast->broadcastAs());
        $this->assertSame(['changed' => true, 'access_changed' => false], $broadcast->broadcastWith());
        $this->assertSame(1, $context->thread()->sole()->messages()->count());
        config()->set('themes.active', 'mskba_app');
        app()->forgetInstance(\App\Presentation\Theming\ThemeResolver::class);
        \Illuminate\Support\Facades\View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));
        $url = route('account.notifications.threads.game-invitation', $context);
        $this->actingAs($ownerB)->get($url)->assertForbidden();
        $this->getJson($url.'?fragment=1')->assertForbidden();
        $readUrl = route('account.notifications.threads.game-invitation.read', $context);
        $this->patchJson($readUrl)->assertForbidden();
        $this->actingAs($ownerA);
        $pendingNotice = \App\Modules\Notification\Domain\Models\UserNotification::query()
            ->where('user_id', $ownerA->id)->where('payload->game_admission_id', $second->id)->sole();
        $this->assertTrue($pendingNotice->isNew());
        $fragment = $this->getJson($url.'?fragment=1')->assertOk()->assertJsonStructure(['html', 'rail_html']);
        $this->assertStringContainsString('Повторное приглашение', $fragment->json('html'));
        $this->assertStringContainsString('Оповестить тиммейтов', $fragment->json('html'));
        $this->assertTrue($pendingNotice->fresh()->isNew());
        $this->patchJson($readUrl)->assertOk()->assertJsonStructure(['unread_count']);
        $this->assertFalse($pendingNotice->fresh()->isNew());
        $this->actingAs($ownerA)->get(route('account.notifications'))->assertOk()->assertSee($url, false);
        $this->get($url)->assertOk()->assertSee('Повторное приглашение')->assertSee('Пришли ещё раз приглашение')
            ->assertSee('Приглашение отклонено')->assertSee('Отказаться')->assertSee('Принять')->assertSee('Оповестить тиммейтов');
        $this->actingAs($ownerA)->postJson($respond, ['decision' => 'accepted'])->assertUnprocessable();
        $this->postJson(route('account.notifications.threads.game-invitation.respond', [$context, $second->id]), ['decision' => 'accepted'])->assertOk();
        $this->getJson($state)->assertOk()->assertJsonPath('invitations.0.status', 'declined')->assertJsonPath('invitations.1.status', 'accepted');
        $this->assertSame('accepted', $second->fresh()->status->value);
        $this->postJson(route('account.notifications.threads.game-invitation.notify-teammates', [$context, $second->id]), ['recipients' => [$otherPlayer->id]])->assertOk()->assertJsonPath('sent', 1);
        $this->assertDatabaseCount('game_invitation_recipients', 2);
        $this->assertSame('declined', $first->fresh()->status->value);
        $this->assertSame(0, $event->primaryGame()->firstOrFail()->admissions()->count());
        $this->assertSame(0, $game->sides()->count());
        $this->postJson(route('account.notifications.threads.game-invitation.notify-teammates', [$context, $second->id]), ['recipients' => [$player->id]])->assertOk()->assertJsonPath('sent', 1);
        $delete = route('events.planned-games.destroy', $params);
        $this->actingAs($ownerA)->deleteJson($delete, ['notify' => true])->assertForbidden();
        $this->actingAs($organizer)->deleteJson($delete)->assertUnprocessable()->assertJsonValidationErrors('notify');
        $this->assertFalse($game->fresh()->trashed());
        $this->deleteJson($delete, ['notify' => true, 'reason' => 'Изменились планы'])->assertOk();
        $archived = \App\Modules\Event\Domain\Models\Game::withTrashed()->findOrFail($game->id);
        $this->assertTrue($archived->trashed());
        $this->assertSame('cancelled', $archived->status->value);
        $this->assertSame('Изменились планы', $archived->cancellation_reason);
        $this->assertSame('declined', $first->fresh()->status->value);
        $this->assertSame('accepted', $second->fresh()->status->value);
        $this->assertDatabaseCount('game_invitation_recipients', 3);
        $this->assertSame(1, $context->thread()->sole()->messages()->count());
        $this->assertSame(1, $event->games()->count());
        $this->assertNotNull($event->fresh()->primary_game_id);
        $cancellationNotices = \App\Modules\Notification\Domain\Models\UserNotification::query()
            ->where('payload->source', 'game.planning.cancelled')->where('payload->game_id', $game->id);
        $this->assertEqualsCanonicalizing([$ownerA->id, $player->id, $otherPlayer->id], $cancellationNotices->pluck('user_id')->all());
        $this->assertStringContainsString('Изменились планы', (clone $cancellationNotices)->where('user_id', $player->id)->sole()->body);
        $this->assertSame($url, (clone $cancellationNotices)->where('user_id', $ownerA->id)->sole()->action_url);
        $this->assertSame(route('events.show', $event->routeIdentifier()), (clone $cancellationNotices)->where('user_id', $player->id)->sole()->action_url);
        $this->deleteJson($delete, ['notify' => true])->assertNotFound();
        $this->assertSame(3, $cancellationNotices->count());
        $this->actingAs($ownerA)->get($url)->assertOk()->assertSee('Игра отменена')->assertSee('Изменились планы')
            ->assertSee('Пришли ещё раз приглашение')->assertDontSee('Оповестить тиммейтов');
        $this->getJson($state)->assertOk()->assertJsonPath('cancellation_reason', 'Изменились планы');
        $cancelledFragment = $this->getJson($url.'?fragment=1')->assertOk()->json('html');
        $this->assertStringContainsString('Игра отменена', $cancelledFragment);
        $this->assertStringNotContainsString('data-game-chat-respond', $cancelledFragment);
        $this->assertStringNotContainsString('data-game-teammates-open', $cancelledFragment);
        $this->postJson($notify, ['recipients' => [$player->id]])->assertUnprocessable();
        $this->postJson($messages, ['body' => 'Понял, спасибо'])->assertCreated();

        // The organizer may explicitly opt out of the cancellation notice.
        $this->actingAs($organizer);
        $silentId = $this->postJson(route('events.planned-games.store', $event->routeIdentifier()), [
            'title' => 'Другая игра', 'format' => 'basketball_5x5',
            'recruitment_mode' => 'preformed_teams', 'team_a_id' => $a->id, 'team_b_id' => $b->id,
        ])->assertCreated()->json('game_id');
        $silentParams = [$event->routeIdentifier(), $silentId];
        $this->postJson(route('events.games.recruitment.invite', $silentParams), ['team_id' => $b->id])->assertOk();
        $this->deleteJson(route('events.planned-games.destroy', $silentParams), ['notify' => false])->assertOk();
        $this->assertSoftDeleted('games', ['id' => $silentId]);
        $this->assertDatabaseCount('game_invitation_threads', 2);
        $this->assertSame(0, \App\Modules\Notification\Domain\Models\UserNotification::query()
            ->where('payload->source', 'game.planning.cancelled')->where('payload->game_id', $silentId)->count());
    }

    public function test_planned_games_have_independent_rosters_periods_and_results(): void
    {
        $organizer = $this->verifiedOrganizer();
        $a = $this->verifiedOrganizer();
        $b = $this->verifiedOrganizer();
        $outsider = $this->verifiedOrganizer();
        $input = [...$this->payload($this->venue(), [$this->slots()[0]]), 'type' => 'game', 'games' => [
            ['title' => 'Первый матч', 'format' => 'streetball_1x1'],
            ['title' => 'Второй матч', 'format' => 'streetball_1x1', 'timing_mode' => 'periods', 'periods_count' => 2],
        ]];
        $this->actingAs($organizer)->postJson(route('events.funnel.store'), $input)->assertCreated();
        $event = Event::query()->sole();
        [$first, $second] = $event->games()->orderBy('id')->get()->all();
        foreach ([$a, $b] as $user) {
            $event->participants()->create([
                'user_id' => $user->id, 'role' => 'participant', 'status' => 'confirmed',
                'participation_roles' => ['player'], 'joined_at' => now(),
                'confirmation_version' => $event->participation_confirmation_version,
            ]);
        }
        $firstParams = [$event->routeIdentifier(), $first->id];
        $secondParams = [$event->routeIdentifier(), $second->id];
        $selection = ['side_a_user_ids' => [$a->id], 'side_b_user_ids' => [$b->id]];
        $firstConfirm = route('events.games.recruitment.event-participants.confirm', $firstParams);
        $secondConfirm = route('events.games.recruitment.event-participants.confirm', $secondParams);
        $this->postJson(route('events.games.start', $secondParams))->assertUnprocessable();
        $this->postJson($secondConfirm, ['side_a_user_ids' => [$a->id], 'side_b_user_ids' => [$outsider->id]])->assertUnprocessable();
        $this->postJson($secondConfirm, ['side_a_user_ids' => [$a->id], 'side_b_user_ids' => [$a->id]])->assertUnprocessable();
        $this->actingAs($outsider)->postJson($secondConfirm, $selection)->assertForbidden();
        $this->actingAs($organizer)->postJson($firstConfirm, $selection)->assertOk();
        $this->postJson($secondConfirm, $selection)->assertOk();
        $this->assertSame(2, $first->rosterEntries()->count());
        $this->assertSame(2, $second->rosterEntries()->count());
        $this->deleteJson(route('events.games.recruitment.unconfirm', $firstParams))->assertOk();
        $this->assertSame(0, $first->rosterEntries()->count());
        $this->assertSame(2, $second->rosterEntries()->count());
        $this->assertSame(2, $event->participants()->occupiesPlayerSpot()->whereIn('user_id', [$a->id, $b->id])->count());
        $this->postJson($firstConfirm, $selection)->assertOk();
        $this->get(route('events.games.manage', $secondParams))->assertOk()->assertSee('Второй матч');
        $this->get(route('events.games.show', $secondParams))->assertOk()->assertSee('Второй матч');
        $this->get(route('events.games.recruitment.panel', [...$secondParams, 'management' => 1]))->assertOk()->assertSee('Стороны утверждены');
        $this->getJson(route('events.games.lifecycle.show', $secondParams))->assertOk()->assertJsonPath('can_start', true);
        $this->postJson(route('events.games.start', $secondParams))->assertOk();
        $this->assertNull($first->fresh()->actual_started_at);
        $this->patchJson(route('events.games.statistics', $secondParams), [
            'scores' => ['A' => 0, 'B' => 0],
            'players' => [$a->id => ['assists' => 1]],
            'action' => ['type' => 'assist', 'user_id' => $a->id, 'payload' => ['field' => 'assists']],
        ])->assertOk();
        $this->assertSame(1, $second->actions()->firstOrFail()->gamePeriod->number);
        $this->assertSame(0, $first->actions()->count());
        $this->patchJson(route('events.games.score', $secondParams), ['scores' => ['A' => 2, 'B' => 0]])->assertOk();
        $this->get(route('events.games.live', $secondParams))->assertOk()->assertSee('aria-label="2 : 0"', false);
        $this->postJson(route('events.games.periods.end', $secondParams))->assertOk();
        $this->postJson(route('events.games.periods.start-next', $secondParams))->assertOk();
        $this->postJson(route('events.games.end', $secondParams))->assertOk();
        $this->patchJson(route('events.games.statistics.complete', $secondParams), [
            'scores' => ['A' => 2, 'B' => 0], 'players' => [$a->id => ['assists' => 1], $b->id => ['assists' => 0]],
        ])->assertOk()->assertJsonPath('completed', true);
        $this->assertSame('confirmed', $second->fresh()->statistics_status->value);
        $this->assertSame('scheduled', $first->fresh()->status->value);
        $this->assertSame(EventStatusEnum::PUBLISHED, $event->fresh()->status);
        $this->postJson(route('events.games.start', $firstParams))->assertOk();
        $this->patchJson(route('events.games.score', $firstParams), ['scores' => ['A' => 1, 'B' => 0]])->assertOk();
        $this->postJson(route('events.games.end', $firstParams))->assertOk();
        $this->patchJson(route('events.games.statistics.complete', $firstParams), [
            'scores' => ['A' => 1, 'B' => 0], 'players' => [$a->id => ['assists' => 0], $b->id => ['assists' => 0]],
        ])->assertOk();
        $this->assertSame(EventStatusEnum::PUBLISHED, $event->fresh()->status);
        $this->assertSame(2, (int) $second->sides()->where('slot', 'A')->value('score'));
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
