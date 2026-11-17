<?php

namespace Tests\Feature\Event;

use App\Modules\Event\Application\UseCases\SubmitEventWizardHandler;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\EventVenueInterest;
use App\Modules\VenueBooking\Application\UseCases\QuoteVenueBookingHandler;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\GameFormatEnum;
use App\Modules\Event\Domain\Enums\GameRecruitmentModeEnum;
use App\Modules\Event\Domain\Enums\GameScoringTypeEnum;
use App\Modules\Event\Domain\Enums\GameTimingModeEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Telegram\Domain\Models\TelegramChat;
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueSchedule;
use App\Modules\Venue\Domain\Models\VenueScheduleInterval;
use App\Modules\VenueBooking\Application\Services\VenueBookingOutboxDispatcher;
use App\Modules\VenueBooking\Application\UseCases\AcceptVenueBookingHandler;
use App\Modules\VenueBooking\Application\UseCases\ConfirmVenueBookingHandler;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EventWizardBookingFirstTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('features.venue_rental.rental_flow', true);
        config()->set('features.venue_rental.booking_events', true);
        config()->set('features.venue_rental_rollout.mode', 'all');
        Carbon::setTestNow('2026-09-01 12:00:00 Europe/Moscow');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_wizard_creates_event_and_interest_first_then_links_only_one_booking_later(): void
    {
        $requester = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $requesterActor = app(CurrentActorResolver::class)->resolve($requester, null);
        $owner = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
        ]);
        $ownerActor = app(CurrentActorResolver::class)->resolve($owner, null);
        $venue = $this->venue($owner);
        $chat = TelegramChat::query()->create([
            'telegram_chat_id' => -100133,
            'title' => 'Игры района',
            'type' => 'supergroup',
            'is_active' => true,
            'publishes_events' => true,
        ]);
        $startsAt = CarbonImmutable::now('Europe/Moscow')->addDays(4)->setTime(18, 0);
        $requestId = (string) Str::uuid();

        $this->actingAs($requester)->getJson(route('events.wizard.venues', [
            'venue_id' => $venue->id,
            'discover_scopes' => 1,
            'confirmed_only' => 1,
            'operational_status' => VenueOperationalStatusEnum::ACTIVE->value,
            'starts_at' => $startsAt->format('Y-m-d\TH:i'),
            'duration_minutes' => 120,
            'booking_scope' => 'whole',
            'limit' => 1,
        ]))
            ->assertOk()
            ->assertJsonPath('venues.0.rental_policy.currency', 'RUB')
            ->assertJsonPath('venues.0.rental_policy.currency_exponent', 2)
            ->assertJsonPath('venues.0.rental_policy.whole_amount_minor', 400000)
            ->assertJsonPath('venues.0.available_scopes', ['whole', 'half_a', 'half_b']);

        $this->actingAs($requester)->getJson(route('events.wizard.venues', [
            'venue_id' => $venue->id,
            'discover_scopes' => 1,
            'confirmed_only' => 1,
            'operational_status' => VenueOperationalStatusEnum::ACTIVE->value,
            'starts_at' => $startsAt->format('Y-m-d\TH:i'),
            'duration_minutes' => 75,
            'booking_scope' => 'whole',
            'limit' => 1,
        ]))
            ->assertOk()
            ->assertJsonPath('venues.0.rental_policy.whole_amount_minor', null)
            ->assertJsonPath('venues.0.rental_policy.half_amount_minor', null);

        $payload = [
            'event_request_id' => $requestId,
            'send_pre_request' => true,
            'venue_id' => $venue->id,
            'booking_scope' => 'whole',
            'title' => 'Игра после подтверждения',
            'type' => 'game',
            'visibility' => 'public',
            'description' => 'Настройки должны пережить подтверждение аренды.',
            'starts_at' => $startsAt->format('Y-m-d\TH:i'),
            'duration_minutes' => 120,
            'max_participants' => 18,
            'side_a_size' => 5,
            'side_b_size' => 5,
            'scoring_type' => GameScoringTypeEnum::BASKETBALL->value,
            'game_format' => GameFormatEnum::BASKETBALL_5X5->value,
            'timing_mode' => GameTimingModeEnum::PERIODS->value,
            'periods_count' => 4,
            'game_recruitment_mode' => GameRecruitmentModeEnum::INDIVIDUAL_DRAFT->value,
            'game_accepts_applications' => true,
            'publish_to_telegram' => true,
            'telegram_chat_ids' => [$chat->id],
        ];
        $response = $this->actingAs($requester)->post(route('events.store'), $payload);
        $repeat = app(SubmitEventWizardHandler::class)->handle($requesterActor, $payload);

        $event = Event::query()->where('title', 'Игра после подтверждения')->firstOrFail();
        $response->assertRedirect(route('events.show', $event->routeIdentifier()));
        $this->assertSame($event->id, $repeat->event->id);
        $this->assertSame(EventStatusEnum::PUBLISHED, $event->status);
        $this->assertTrue($event->booking_deferred);
        $this->assertNull($event->booking_id);
        $this->assertDatabaseCount('venue_bookings', 0);
        $this->assertDatabaseCount('events', 1);
        $this->assertDatabaseCount('venue_booking_event_intents', 0);
        $interest = EventVenueInterest::query()->where('event_id', $event->id)->sole();
        $this->assertSame('open', $interest->status);
        $this->assertDatabaseHas('venue_booking_conversations', [
            'event_venue_interest_id' => $interest->id,
            'venue_booking_id' => null,
        ]);
        $quote = app(QuoteVenueBookingHandler::class)->handle(
            $venue, $startsAt, 120, VenueBookingScopeEnum::WHOLE,
            $requester, $venue->primaryCourt()->firstOrFail(),
        );
        $this->actingAs($requester)->post(route('events.booking.store', $event->routeIdentifier()), [
            'expected_version' => $event->participation_confirmation_version,
            'quote_id' => $quote->publicId,
            'interest_id' => $interest->id,
        ])->assertSessionHasNoErrors();
        $booking = VenueBooking::query()->sole();
        $this->assertSame($event->id, $booking->event_id);
        $this->assertSame($interest->id, $booking->event_venue_interest_id);
        $this->assertSame('organizer', $booking->interest_booking_initiator);
        $this->assertSame(VenueBookingStatusEnum::REQUESTED, $booking->status);
        $this->assertSame($booking->id, $event->refresh()->booking_id);
        $this->assertDatabaseHas('venue_booking_conversations', [
            'event_venue_interest_id' => $interest->id,
            'venue_booking_id' => $booking->id,
        ]);

        $booking = app(AcceptVenueBookingHandler::class)->handle(
            $booking->id,
            $ownerActor,
            $booking->optimistic_version,
            (string) Str::uuid(),
        );
        app(ConfirmVenueBookingHandler::class)->handle(
            $booking->id,
            $ownerActor,
            $booking->optimistic_version,
            (string) Str::uuid(),
        );
        app(VenueBookingOutboxDispatcher::class)->dispatchPending();
        app(VenueBookingOutboxDispatcher::class)->dispatchPending();

        $event = $event->refresh()->load('primaryGame');
        $this->assertSame(EventStatusEnum::PUBLISHED, $event->status);
        $this->assertSame(GameFormatEnum::BASKETBALL_5X5, $event->primaryGame->format);
        $this->assertSame(GameTimingModeEnum::PERIODS, $event->primaryGame->timing_mode);
        $this->assertSame(GameRecruitmentModeEnum::INDIVIDUAL_DRAFT, $event->primaryGame->recruitment_mode);
        $this->assertSame(4, $event->primaryGame->periods_count);
        $this->assertDatabaseHas('telegram_event_publications', [
            'event_id' => $event->id,
            'chat_id' => (string) $chat->telegram_chat_id,
            'status' => 'pending',
        ]);
        $this->assertDatabaseCount('events', 1);
    }

    public function test_wizard_can_publish_interest_without_activating_rental_flow(): void
    {
        config()->set('features.venue_rental_rollout.mode', 'internal');

        $requester = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::USER,
        ]);
        $owner = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
        ]);
        $venue = $this->venue($owner);
        $startsAt = CarbonImmutable::now('Europe/Moscow')->addDays(4)->setTime(18, 0);

        $this->actingAs($requester)
            ->from(route('events.wizard', ['type' => 'game']))
            ->post(route('events.store'), [
                'event_request_id' => (string) Str::uuid(),
                'venue_id' => $venue->id,
                'booking_scope' => 'whole',
                'title' => 'Игра вне rollout',
                'type' => 'game',
                'visibility' => 'public',
                'starts_at' => $startsAt->format('Y-m-d\TH:i'),
                'duration_minutes' => 120,
                'max_participants' => 10,
                'side_a_size' => 5,
                'side_b_size' => 5,
                'scoring_type' => GameScoringTypeEnum::BASKETBALL->value,
                'game_format' => GameFormatEnum::BASKETBALL_5X5->value,
                'timing_mode' => GameTimingModeEnum::PERIODS->value,
                'periods_count' => 4,
                'game_recruitment_mode' => GameRecruitmentModeEnum::INDIVIDUAL_DRAFT->value,
                'game_accepts_applications' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('venue_bookings', 0);
        $this->assertDatabaseCount('venue_booking_event_intents', 0);
        $this->assertDatabaseCount('events', 1);
        $this->assertTrue(Event::query()->sole()->booking_deferred);
    }


    public function test_venue_representative_offers_booking_but_organizer_must_accept_and_thread_survives(): void
    {
        $organizer = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $representative = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
        ]);
        $venue = $this->venue($representative);
        $startsAt = CarbonImmutable::now('Europe/Moscow')->addDays(4)->setTime(18, 0);
        $this->actingAs($organizer)->post(route('events.store'), [
            'venue_id' => $venue->id,
            'type' => 'training',
            'visibility' => 'public',
            'title' => 'Игра с предварительной перепиской',
            'starts_at' => $startsAt->format('Y-m-d\TH:i'),
            'duration_minutes' => 120,
            'send_pre_request' => true,
        ])->assertSessionHasNoErrors();

        $event = Event::query()->sole();
        $interest = $event->venueInterests()->sole();
        $thread = route('events.interests.show', [
            'event' => $event->routeIdentifier(),
            'interest' => $interest->id,
        ]);
        $this->actingAs($organizer)->get($thread)->assertOk()->assertSee('Переписка');
        $stranger = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $this->actingAs($stranger)->get($thread)->assertForbidden();

        $this->actingAs($representative)->post(route('events.interests.messages.store', [
            'event' => $event->routeIdentifier(),
            'interest' => $interest->id,
        ]), [
            'client_id' => (string) Str::uuid(),
            'body' => 'Предзаявка ещё актуальна?',
        ])->assertSessionHasNoErrors();
        $this->actingAs($organizer)->get($thread)->assertOk()
            ->assertSee('Предзаявка ещё актуальна?');

        $this->actingAs($representative)->post(route('events.interests.proposals.store', [
            'event' => $event->routeIdentifier(),
            'interest' => $interest->id,
        ]))->assertSessionHasNoErrors();

        $proposal = \App\Modules\Event\Domain\Models\EventVenueBookingProposal::query()->sole();
        $this->assertSame('pending', $proposal->status);
        $this->assertSame($representative->id, $proposal->initiator->user_id);
        $this->assertDatabaseCount('venue_bookings', 0);

        $this->actingAs($organizer)->post(route('events.booking.quote', $event->routeIdentifier()), [
            'expected_version' => $event->participation_confirmation_version,
            'interest_id' => $interest->id,
            'proposal_id' => $proposal->id,
        ])->assertOk()->assertSee('Принять предложение и отправить заявку');

        $quote = app(QuoteVenueBookingHandler::class)->handle(
            $venue, $startsAt, 120, VenueBookingScopeEnum::WHOLE,
            $organizer, $venue->primaryCourt()->firstOrFail(),
        );
        $this->actingAs($organizer)->post(route('events.booking.store', $event->routeIdentifier()), [
            'expected_version' => $event->participation_confirmation_version,
            'quote_id' => $quote->publicId,
            'interest_id' => $interest->id,
            'proposal_id' => $proposal->id,
        ])->assertSessionHasNoErrors();

        $booking = VenueBooking::query()->sole();
        $this->assertSame('venue', $booking->interest_booking_initiator);
        $this->assertSame($interest->id, $booking->event_venue_interest_id);
        $this->assertSame('accepted', $proposal->refresh()->status);
        $this->assertSame($booking->id, $proposal->booking_id);
        $this->assertDatabaseHas('venue_booking_conversations', [
            'event_venue_interest_id' => $interest->id,
            'venue_booking_id' => $booking->id,
        ]);
        $this->actingAs($organizer)->get($thread)->assertOk()->assertSee('Заявка на бронь');
        $this->actingAs($organizer)->get(route('account.venue-bookings.show', $booking))
            ->assertOk()->assertSee('Открыть общую переписку');
    }

    public function test_event_venue_time_revision_keeps_old_values_and_revises_nonbinding_interest(): void
    {
        $organizer = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $representative = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
        ]);
        $venue = $this->venue($representative);
        $start = CarbonImmutable::now('Europe/Moscow')->addDays(4)->setTime(18, 0);
        $this->actingAs($organizer)->post(route('events.store'), [
            'venue_id' => $venue->id, 'type' => 'training', 'visibility' => 'public',
            'title' => 'Тренировка с переносом', 'starts_at' => $start->format('Y-m-d\TH:i'),
            'duration_minutes' => 60, 'send_pre_request' => true,
        ])->assertSessionHasNoErrors();
        $event = Event::query()->sole();
        $first = $event->venueInterests()->sole();
        $newStart = $start->addHour();

        $this->actingAs($organizer)->put(route('events.update', $event->routeIdentifier()), [
            'title' => $event->title, 'venue_id' => $venue->id,
            'type' => 'training', 'visibility' => 'public',
            'starts_at' => $newStart->format('Y-m-d\TH:i'), 'duration_minutes' => 60,
            'revision_reason' => 'Подстроили время под команду.',
        ])->assertSessionHasNoErrors();

        $this->assertSame('revised', $first->refresh()->status);
        $second = $event->venueInterests()->where('status', 'open')->sole();
        $this->assertSame(2, $second->revision);
        $this->assertSame(2, $event->refresh()->participation_confirmation_version);
        $this->assertDatabaseCount('venue_bookings', 0);
        $this->assertDatabaseHas('event_venue_revisions', [
            'event_id' => $event->id, 'revision' => 1,
            'previous_venue_id' => $venue->id, 'next_venue_id' => $venue->id,
            'reason' => 'Подстроили время под команду.',
        ]);
        $this->actingAs($organizer)->get(route('events.show', $event->routeIdentifier()))
            ->assertOk()->assertSee('История изменений места и времени')
            ->assertSee('Подстроили время под команду.');
    }

    private function venue(User $publisher): Venue
    {
        $venue = Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED,
            'operational_status' => VenueOperationalStatusEnum::ACTIVE,
            'requires_payment' => true,
            'requires_booking_approval' => true,
        ]);
        $venue->characteristics()->create(['hoops_count' => 2]);
        $venue->primaryCourt()->firstOrFail()->update([
            'hoops_count' => 2,
            'supports_halves' => true,
            'allows_halves' => true,
        ]);
        $schedule = VenueSchedule::factory()->for($venue)->create(['timezone' => 'Europe/Moscow']);
        VenueScheduleInterval::factory()->for($schedule, 'schedule')->create([
            'day_of_week' => CarbonImmutable::now('Europe/Moscow')->addDays(4)->isoWeekday(),
            'starts_at' => '09:00',
            'ends_at' => '23:00',
            'sort_order' => 0,
        ]);
        VenueBookingPolicy::query()->create([
            'venue_id' => $venue->id,
            'version' => 1,
            'is_enabled' => true,
            'allows_whole' => true,
            'allows_halves' => true,
            'minimum_duration_minutes' => 60,
            'maximum_duration_minutes' => 240,
            'time_step_minutes' => 30,
            'minimum_lead_time_minutes' => 120,
            'maximum_advance_days' => 90,
            'currency' => 'RUB',
            'whole_price_per_step_minor' => 100000,
            'half_price_per_step_minor' => 60000,
            'hold_duration_minutes' => 30,
            'allows_hold_extension' => false,
            'maximum_hold_extension_minutes' => null,
            'requires_payment' => false,
            'payment_window_minutes' => null,
            'quote_validity_minutes' => 15,
            'cancellation_before_minutes' => 1440,
            'published_by_user_id' => $publisher->id,
            'published_at' => now(),
            'active_marker' => true,
        ]);

        return $venue;
    }
}
