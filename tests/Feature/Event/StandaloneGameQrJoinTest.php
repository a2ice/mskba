<?php

namespace Tests\Feature\Event;

use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\GameAdmissionStatusEnum;
use App\Modules\Event\Domain\Enums\GameRecruitmentModeEnum;
use App\Modules\Event\Domain\Enums\GameStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Domain\Models\UserNotification;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueSchedule;
use App\Modules\Venue\Domain\Models\VenueScheduleInterval;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class StandaloneGameQrJoinTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_join_page_warns_existing_mini_app_users_and_opens_existing_auth_flow(): void
    {
        $organizer = $this->confirmedUser('qr-organizer');
        $game = $this->createStandaloneGame($organizer, GameRecruitmentModeEnum::INDIVIDUAL_DRAFT);
        $route = [$game->event->routeIdentifier(), $game->id];
        $joinUrl = route('events.games.recruitment.join', $route, false);

        $this->post(route('auth.logout'))->assertRedirect();

        $this->get($joinUrl)
            ->assertOk()
            ->assertSee('Уже пользовались MSKBA? Не создавайте второй аккаунт.')
            ->assertSee('Telegram Mini App')
            ->assertSee('VK ID')
            ->assertSee('Войти или зарегистрироваться')
            ->assertSee('data-modal-target="auth-entry-classic"', false)
            ->assertSee('data-auth-redirect-url="'.$joinUrl.'"', false);
    }

    public function test_authenticated_player_can_apply_from_qr_flow_and_see_pending_status(): void
    {
        $organizer = $this->confirmedUser('qr-apply-organizer');
        $player = $this->confirmedUser('qr-player');
        $game = $this->createStandaloneGame($organizer, GameRecruitmentModeEnum::INDIVIDUAL_DRAFT);
        $route = [$game->event->routeIdentifier(), $game->id];
        $joinUrl = route('events.games.recruitment.join', $route);

        $this->actingAs($player)
            ->get($joinUrl)
            ->assertOk()
            ->assertSee('Подать заявку на игру');

        $this->actingAs($player)
            ->from($joinUrl)
            ->post(route('events.games.recruitment.join.apply', $route))
            ->assertRedirect($joinUrl);

        $admission = $game->admissions()->whereIn('user_id', $player->canonical()->identityIds())->latest('id')->firstOrFail();
        $this->assertSame(GameAdmissionStatusEnum::PENDING, $admission->status);

        $this->actingAs($player)
            ->get($joinUrl)
            ->assertOk()
            ->assertSee('Заявка отправлена')
            ->assertSee('data-pending-admission-id="'.$admission->id.'"', false)
            ->assertSee('статус обновится автоматически в realtime');
    }

    public function test_organizer_decision_before_formation_uses_existing_admission_flow_and_join_page_updates_state(): void
    {
        $organizer = $this->confirmedUser('qr-decision-organizer');
        $player = $this->confirmedUser('qr-decision-player');
        $game = $this->createStandaloneGame($organizer, GameRecruitmentModeEnum::INDIVIDUAL_DRAFT);
        $route = [$game->event->routeIdentifier(), $game->id];

        $this->actingAs($player)
            ->postJson(route('events.games.recruitment.join.apply', $route))
            ->assertOk();
        $admission = $game->admissions()->whereIn('user_id', $player->canonical()->identityIds())->latest('id')->firstOrFail();

        $this->actingAs($organizer)
            ->postJson(route('events.games.recruitment.respond', [...$route, $admission->id]), [
                'decision' => GameAdmissionStatusEnum::ACCEPTED->value,
            ])
            ->assertOk();

        $notification = UserNotification::query()
            ->where('user_id', $player->id)
            ->where('title', 'Заявка на игру принята')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('game.recruitment', $notification->payload['source'] ?? null);
        $this->assertSame($game->id, (int) ($notification->payload['game_id'] ?? 0));

        $this->actingAs($player)
            ->get(route('events.games.recruitment.join', $route))
            ->assertOk()
            ->assertSee('Заявка принята')
            ->assertSee('balanced-формирование');
    }

    public function test_player_can_apply_after_start_and_organizer_assigns_them_directly_to_existing_side(): void
    {
        $organizer = $this->confirmedUser('qr-late-organizer');
        $first = $this->confirmedUser('qr-late-first');
        $second = $this->confirmedUser('qr-late-second');
        $late = $this->confirmedUser('qr-late-player');
        $game = $this->createStandaloneGame($organizer, GameRecruitmentModeEnum::INDIVIDUAL_DRAFT);
        $route = [$game->event->routeIdentifier(), $game->id];

        $this->acceptInitialApplication($game, $organizer, $first);
        $this->acceptInitialApplication($game, $organizer, $second);
        $this->confirmBalancedSides($game, $organizer);

        $this->actingAs($organizer)
            ->postJson(route('events.games.start', $route))
            ->assertOk();
        $this->assertSame(GameStatusEnum::IN_PROGRESS, $game->fresh()->status);

        $joinUrl = route('events.games.recruitment.join', $route);
        $this->actingAs($late)
            ->get($joinUrl)
            ->assertOk()
            ->assertSee('Игра уже идёт')
            ->assertSee('Подать заявку на игру');

        $this->actingAs($late)
            ->postJson(route('events.games.recruitment.join.apply', $route))
            ->assertOk();
        $admission = $game->admissions()->whereIn('user_id', $late->canonical()->identityIds())->latest('id')->firstOrFail();
        $this->assertSame(GameAdmissionStatusEnum::PENDING, $admission->status);

        $side = $game->sides()->orderBy('slot')->firstOrFail();
        $this->actingAs($organizer)
            ->postJson(route('events.games.recruitment.late.accept', [...$route, $admission->id]), [
                'side' => $side->slot,
            ])
            ->assertOk();

        $this->assertSame(GameAdmissionStatusEnum::ACCEPTED, $admission->fresh()->status);
        $this->assertDatabaseHas('game_roster_entries', [
            'game_id' => $game->id,
            'game_side_id' => $side->id,
            'user_id' => $late->canonical()->id,
            'status' => 'selected',
        ]);
        $this->assertDatabaseHas('event_participants', [
            'event_id' => $game->event_id,
            'user_id' => $late->canonical()->id,
            'status' => 'confirmed',
        ]);

        $this->actingAs($late)
            ->get($joinUrl)
            ->assertOk()
            ->assertSee('Заявка принята')
            ->assertSee($side->display_name)
            ->assertSee('Игра уже идёт — можно подключаться.');
    }

    public function test_organizer_can_stop_late_applications_without_ending_active_game(): void
    {
        $organizer = $this->confirmedUser('qr-stop-organizer');
        $first = $this->confirmedUser('qr-stop-first');
        $second = $this->confirmedUser('qr-stop-second');
        $game = $this->createStandaloneGame($organizer, GameRecruitmentModeEnum::INDIVIDUAL_DRAFT);
        $route = [$game->event->routeIdentifier(), $game->id];

        $this->acceptInitialApplication($game, $organizer, $first);
        $this->acceptInitialApplication($game, $organizer, $second);
        $this->confirmBalancedSides($game, $organizer);
        $this->actingAs($organizer)->postJson(route('events.games.start', $route))->assertOk();

        $this->actingAs($organizer)
            ->patchJson(route('events.games.recruitment.late.applications', $route), ['enabled' => false])
            ->assertOk();

        $player = $this->confirmedUser('qr-stop-player');
        $this->actingAs($player)
            ->get(route('events.games.recruitment.join', $route))
            ->assertOk()
            ->assertSee('Набор на эту игру закрыт.')
            ->assertDontSee('Подать заявку на игру');

        $this->actingAs($player)
            ->postJson(route('events.games.recruitment.join.apply', $route))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Сейчас новые заявки на эту игру не принимаются.');
    }

    public function test_qr_join_is_only_available_for_individual_draft_standalone_games(): void
    {
        $organizer = $this->confirmedUser('qr-mode-organizer');
        $game = $this->createStandaloneGame($organizer, GameRecruitmentModeEnum::PREFORMED_TEAMS);

        $this->get(route('events.games.recruitment.join', [$game->event->routeIdentifier(), $game->id]))
            ->assertNotFound();
    }

    public function test_join_page_remains_readable_but_stops_offering_application_when_recruitment_is_closed(): void
    {
        $organizer = $this->confirmedUser('qr-closed-organizer');
        $game = $this->createStandaloneGame($organizer, GameRecruitmentModeEnum::INDIVIDUAL_DRAFT);
        $route = [$game->event->routeIdentifier(), $game->id];

        $this->actingAs($organizer)
            ->patchJson(route('events.games.recruitment.applications', $route), ['enabled' => false])
            ->assertOk();

        $player = $this->confirmedUser('qr-closed-player');
        $this->actingAs($player)
            ->get(route('events.games.recruitment.join', $route))
            ->assertOk()
            ->assertSee('Набор на эту игру закрыт.')
            ->assertDontSee('Подать заявку на игру');
    }

    private function acceptInitialApplication($game, User $organizer, User $player): void
    {
        $route = [$game->event->routeIdentifier(), $game->id];
        $this->actingAs($player)
            ->postJson(route('events.games.recruitment.join.apply', $route))
            ->assertOk();
        $admission = $game->admissions()->whereIn('user_id', $player->canonical()->identityIds())->latest('id')->firstOrFail();
        $this->actingAs($organizer)
            ->postJson(route('events.games.recruitment.respond', [...$route, $admission->id]), [
                'decision' => GameAdmissionStatusEnum::ACCEPTED->value,
            ])
            ->assertOk();
    }

    private function confirmBalancedSides($game, User $organizer): void
    {
        $route = [$game->event->routeIdentifier(), $game->id];
        $preview = $this->actingAs($organizer)
            ->postJson(route('events.games.recruitment.formation.preview', $route), [
                'assessment_source' => 'self_assessment',
                'seed' => 108,
            ])
            ->assertOk()
            ->json();

        $payload = [
            'pool_fingerprint' => $preview['pool_fingerprint'],
            'teams' => collect($preview['teams'])->map(fn (array $team): array => [
                'number' => $team['number'],
                'name' => $team['name'],
                'logo_preset' => $team['logo_preset'],
                'user_ids' => collect($team['players'])->pluck('id')->all(),
            ])->all(),
        ];

        $this->actingAs($organizer)
            ->postJson(route('events.games.recruitment.formation.apply', $route), $payload)
            ->assertOk();
        $game->refresh();
        $this->assertNotNull($game->sides_confirmed_at);
    }

    private function createStandaloneGame(User $organizer, GameRecruitmentModeEnum $mode)
    {
        [$venue, $start] = $this->availableVenue();
        $payload = [
            'venue_id' => $venue->id,
            'title' => 'QR join '.$organizer->username,
            'type' => EventTypeEnum::GAME->value,
            'visibility' => 'public',
            'description' => null,
            'starts_at' => $start->format('Y-m-d\\TH:i'),
            'duration_minutes' => 90,
            'max_participants' => 20,
            'game_recruitment_mode' => $mode->value,
            'game_accepts_applications' => true,
            'game_format' => 'streetball_1x1',
            'side_a_size' => 1,
            'side_b_size' => 1,
            'scoring_type' => 'streetball',
            'timing_mode' => 'whole_game',
            'publish_to_telegram' => false,
        ];

        $this->actingAs($organizer)
            ->post(route('events.store'), $payload)
            ->assertRedirect();

        return Event::query()
            ->where('title', $payload['title'])
            ->firstOrFail()
            ->primaryGame()
            ->firstOrFail()
            ->load('event');
    }

    private function confirmedUser(string $username): User
    {
        return User::factory()->create([
            'username' => $username,
            'status' => UserStatusEnum::CONFIRMED,
        ]);
    }

    /** @return array{Venue, CarbonImmutable} */
    private function availableVenue(): array
    {
        $start = CarbonImmutable::now('Europe/Moscow')->addDays(7)->setTime(12, 0);
        $venue = Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED->value,
            'requires_payment' => false,
            'requires_booking_approval' => false,
        ]);
        $schedule = VenueSchedule::factory()->for($venue)->create(['timezone' => 'Europe/Moscow']);
        VenueScheduleInterval::factory()->for($schedule, 'schedule')->create([
            'day_of_week' => $start->isoWeekday(),
            'starts_at' => '09:00',
            'ends_at' => '18:00',
            'sort_order' => 0,
        ]);

        return [$venue, $start];
    }
}
