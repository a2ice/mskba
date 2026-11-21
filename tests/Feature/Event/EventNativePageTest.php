<?php

namespace Tests\Feature\Event;

use App\Modules\Event\Domain\Enums\EventParticipantRoleEnum;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Presentation\Theming\ThemeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EventNativePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('themes.active', 'mskba_app');
        app()->forgetInstance(ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));
        View::getFinder()->flush();
    }

    private function createEvent(User $user, Venue $venue, array $extra = []): Event
    {
        $this->actingAs($user)->postJson(route('events.funnel.store'), [
            'event_request_id' => (string) Str::uuid(),
            'venue_id' => $venue->id,
            'type' => 'training',
            'title' => 'Новая встреча',
            'visibility' => 'public',
            'slots' => [],
            ...$extra,
        ])->assertCreated();

        return Event::query()->latest('id')->firstOrFail();
    }

    private function venue(): Venue
    {
        return Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED,
            'is_published' => true,
            'raw_address' => 'Тестовый адрес площадки',
        ]);
    }

    public function test_undated_page_keeps_organizer_and_discussion_and_does_not_book(): void
    {
        $user = User::factory()->withVerifiedContact()->create(['status' => UserStatusEnum::CONFIRMED]);
        $venue = $this->venue();
        $venue->courts()->first() ?? $venue->courts()->create([
            'name' => 'Основной зал', 'alias' => 'court-main', 'is_primary' => true, 'sort_order' => 10,
        ]);
        $event = $this->createEvent($user, $venue);
        $url = route('events.show', $event->routeIdentifier());

        $this->assertNull($event->venue_court_id);
        $this->get($url)->assertOk()
            ->assertSee('data-native-event-page', false)
            ->assertSee('Черновик')
            ->assertSee('Дата и время не определены')
            ->assertDontSee('не более 3 действий в минуту')
            ->assertDontSee('Договоритесь о деталях игры.')
            ->assertSee('Любой зал')
            ->assertSee($venue->name)
            ->assertSee('Посмотреть на карте')
            ->assertDontSee('Посмотреть занятость')
            ->assertSee('data-venue-play-page', false)
            ->assertSee('data-venue-play-message-form', false)
            ->assertSee('data-venue-play-edit-cancel', false)
            ->assertSee('data-chat-composer', false)
            ->assertSee('data-chat-actions', false)
            ->assertSee('data-event-response-choice="confirmed"', false)
            ->assertSee('data-event-response-choice="tentative"', false)
            ->assertSee('Как это было')
            ->assertSee('Материалов по данному мероприятию пока нет')
            ->assertDontSee('data-chat-action="event-rsvp"', false)
            ->assertSee('data-chat-context-delete hidden', false)
            ->assertSee('data-venue-play-delete-dialog', false)
            ->assertSee('Указать дату и время')
            ->assertDontSee('id="event-booking"', false);

        $this->patchJson(route('events.participation', $event->routeIdentifier()), ['status' => 'left'])->assertOk();
        $organizer = $event->participants()->firstOrFail();
        $this->assertSame(EventParticipantRoleEnum::ORGANIZER, $organizer->role);
        $this->assertSame(EventParticipantStatusEnum::LEFT, $organizer->status);
        $this->get($url)->assertOk()->assertSee('data-event-status="left"', false)->assertSee('data-venue-play-message-form', false);

        $this->get(route('events.edit', $event->routeIdentifier()))->assertOk();
        $this->put(route('events.update', $event->routeIdentifier()), [
            'title' => 'Черновик изменён', 'type' => 'training', 'visibility' => 'public',
            'description' => 'Тест', 'max_participants' => 10,
        ])->assertRedirect();
        $this->assertNull($event->fresh()->starts_at);
        $this->assertSame(EventStatusEnum::DRAFT, $event->fresh()->status);
        $this->assertDatabaseCount('venue_bookings', 0);

        $this->actingAs(User::factory()->withVerifiedContact()->create(['status' => UserStatusEnum::CONFIRMED]))
            ->get($url)->assertNotFound();
    }

    public function test_existing_event_requires_contact_for_booking_but_remains_visible(): void
    {
        $owner = User::factory()->withVerifiedContact()->create(['status' => UserStatusEnum::CONFIRMED]);
        $venue = $this->venue();
        $court = $venue->courts()->firstOrFail();
        $start = CarbonImmutable::now('Europe/Moscow')->addDays(2)->setTime(19, 0);
        $event = $this->createEvent($owner, $venue, [
            'venue_court_id' => $court->id,
            'slots' => [['starts_at' => $start->format('Y-m-d\TH:i'), 'duration_minutes' => 90]],
        ]);
        $owner->contacts()->update(['verified_at' => null]);
        $url = route('events.show', $event->routeIdentifier());

        $this->get($url)->assertOk()
            ->assertSee('Для бронирования необходимо иметь подтверждённый контакт')
            ->assertSee('Продолжить подтверждение')
            ->assertSee($start->locale('ru')->translatedFormat('j F Y · H:i').'–20:30')
            ->assertDontSee('Посмотреть занятость');
        foreach (['events.booking.quote', 'events.booking.store'] as $route) {
            $this->post(route($route, $event->routeIdentifier()), [
                'expected_version' => $event->participation_confirmation_version,
            ])->assertRedirect($url)
                ->assertSessionHas('error', 'Для бронирования необходимо иметь подтверждённый контакт');
        }
        $this->assertDatabaseCount('events', 1);
        $this->assertDatabaseCount('venue_bookings', 0);
    }

    public function test_event_rental_shows_linked_request_status_before_confirmation(): void
    {
        $owner = User::factory()->withVerifiedContact()->create(['status' => UserStatusEnum::CONFIRMED]);
        $venue = $this->venue();
        $start = CarbonImmutable::now('Europe/Moscow')->addDays(2)->setTime(19, 0);
        $event = $this->createEvent($owner, $venue, [
            'slots' => [['starts_at' => $start->format('Y-m-d\\TH:i'), 'duration_minutes' => 90]],
        ]);
        $booking = $event->booking()->create([
            'venue_id' => $venue->id,
            'created_by_actor_id' => $event->organizer_actor_id,
            'status' => VenueBookingStatusEnum::REQUESTED,
            'scope' => VenueBookingScopeEnum::WHOLE,
            'starts_at' => $event->starts_at,
            'ends_at' => $event->ends_at,
        ]);
        $url = route('events.show', $event->routeIdentifier());
        foreach ([VenueBookingStatusEnum::REQUESTED, VenueBookingStatusEnum::HELD, VenueBookingStatusEnum::CONFIRMED] as $status) {
            $booking->update(['status' => $status]);
            $this->get($url)->assertOk()
                ->assertSee('data-event-rental-status="'.$status->value.'"', false)
                ->assertSee($status === VenueBookingStatusEnum::REQUESTED ? 'На рассмотрении' : $status->label())
                ->assertDontSee('data-event-rental-open>', false);
        }
        $this->assertSame($event->id, $booking->fresh()->event_id);
        $this->assertDatabaseCount('venue_bookings', 1);
    }

    public function test_payment_status_is_independent_from_booking_and_private_to_payer(): void
    {
        $owner = User::factory()->withVerifiedContact()->create(['status' => UserStatusEnum::CONFIRMED]);
        $venue = $this->venue();
        $start = CarbonImmutable::now('Europe/Moscow')->addDays(2)->setTime(19, 0);
        $event = $this->createEvent($owner, $venue, [
            'slots' => [['starts_at' => $start->format('Y-m-d\\TH:i'), 'duration_minutes' => 90]],
        ]);
        $booking = $event->booking()->create([
            'venue_id' => $venue->id, 'created_by_actor_id' => $event->organizer_actor_id,
            'status' => VenueBookingStatusEnum::HELD, 'scope' => VenueBookingScopeEnum::WHOLE,
            'starts_at' => $event->starts_at, 'ends_at' => $event->ends_at,
        ]);
        DB::table('venue_bookings')->where('id', $booking->id)->update([
            'requester_user_id' => $owner->id, 'payment_state' => 'not_started',
            'quote_snapshot' => json_encode(['policy' => ['invoice_workflow' => true, 'requires_payment' => true]]),
        ]);
        $event->forceFill(['booking_id' => $booking->id])->save();
        $url = route('events.show', $event->routeIdentifier());
        $this->get($url)->assertOk()
            ->assertSee('data-event-rental-payment="awaiting_invoice"', false);
        $invoice = \App\Modules\VenueBooking\Domain\Models\VenueRentalInvoice::query()->create([
            'public_id' => (string) Str::uuid(), 'number' => 'INV-963-TEST',
            'venue_booking_id' => $booking->id, 'kind' => 'buyer',
            'issuer_kind' => 'portal', 'payer_kind' => 'buyer', 'payer_user_id' => $owner->id,
            'status' => 'issued', 'currency' => 'RUB', 'amount_minor' => 10000,
            'title' => 'Аренда', 'payment_methods' => ['sbp'], 'quote_snapshot' => [],
            'issued_at' => now(), 'due_at' => now()->addMinutes(30),
        ]);
        foreach ([
            'issued' => 'Счёт выставлен',
            'claimed' => 'Ожидает подтверждения оплаты',
            'paid' => 'Счёт оплачен',
            'postpay' => 'Постоплата согласована',
            'void' => 'Счёт отменён',
        ] as $state => $label) {
            $invoice->update(['status' => $state]);
            $this->get($url)->assertOk()
                ->assertSee('data-event-rental-status="held"', false)
                ->assertSee('data-event-rental-payment="'.$state.'"', false)
                ->assertSee($label);
        }
        $invoice->update(['status' => 'postpay']);
        $booking->update(['status' => VenueBookingStatusEnum::CONFIRMED]);
        $this->get($url)->assertOk()
            ->assertSee('data-event-rental-status="confirmed"', false)
            ->assertSee('data-event-rental-payment="postpay"', false);
        $other = User::factory()->withVerifiedContact()->create(['status' => UserStatusEnum::CONFIRMED]);
        $this->actingAs($other)->get($url)->assertOk()
            ->assertSee('data-event-rental-status="confirmed"', false)
            ->assertDontSee('data-event-rental-payment="', false)
            ->assertDontSee('Постоплата согласована');
    }

    public function test_published_event_uses_nonprimary_selected_hall_and_booking_popup(): void
    {
        $owner = User::factory()->withVerifiedContact()->create(['status' => UserStatusEnum::CONFIRMED]);
        $venue = $this->venue();
        $venue->courts()->first() ?? $venue->courts()->create([
            'name' => 'Основной зал', 'alias' => 'court-main', 'is_primary' => true, 'sort_order' => 10,
        ]);
        $court = $venue->courts()->create([
            'name' => 'Испытательный зал', 'alias' => 'court-test', 'is_primary' => false, 'sort_order' => 20,
        ]);
        $start = CarbonImmutable::now('Europe/Moscow')->addDays(2)->setTime(19, 0);
        $event = $this->createEvent($owner, $venue, [
            'venue_court_id' => $court->id,
            'slots' => [['starts_at' => $start->format('Y-m-d\TH:i'), 'duration_minutes' => 90]],
        ]);
        $this->assertSame($court->id, $event->venue_court_id);

        $booking = $event->booking()->create([
            'venue_id' => $venue->id,
            'venue_court_id' => $court->id,
            'created_by_actor_id' => $event->organizer_actor_id,
            'status' => VenueBookingStatusEnum::CONFIRMED,
            'scope' => VenueBookingScopeEnum::WHOLE,
            'starts_at' => $event->starts_at,
            'ends_at' => $event->ends_at,
        ]);
        $this->assertSame($court->id, $booking->venue_court_id);
        $reader = User::factory()->withVerifiedContact()->create(['status' => UserStatusEnum::CONFIRMED]);
        $this->actingAs($reader)->get(route('events.show', $event->routeIdentifier()))
            ->assertOk()->assertSee('data-native-event-page', false)
            ->assertSee('Испытательный зал')
            ->assertSee('19:00')
            ->assertSee('data-event-dialog-open="event-booking"', false)
            ->assertSee('id="event-booking"', false)
            ->assertSee('Бронирование №'.$booking->id)
            ->assertDontSee('Пригласить участников')
            ->assertDontSee('Перейти к заявке');
    }

    public function test_organizer_can_delete_and_restore_with_reason_and_personal_notifications(): void
    {
        $owner = User::factory()->withVerifiedContact()->create(['status' => UserStatusEnum::CONFIRMED]);
        $going = User::factory()->withVerifiedContact()->create(['status' => UserStatusEnum::CONFIRMED]);
        $thinking = User::factory()->withVerifiedContact()->create(['status' => UserStatusEnum::CONFIRMED]);
        $declined = User::factory()->withVerifiedContact()->create(['status' => UserStatusEnum::CONFIRMED]);
        $venue = $this->venue();
        $start = CarbonImmutable::now('Europe/Moscow')->addDays(2)->setTime(19, 0);
        $event = $this->createEvent($owner, $venue, [
            'slots' => [['starts_at' => $start->format('Y-m-d\\TH:i'), 'duration_minutes' => 90]],
        ]);
        foreach ([[$going, 'confirmed'], [$thinking, 'tentative'], [$declined, 'left']] as [$user, $status]) {
            $event->participants()->create([
                'user_id' => $user->id,
                'role' => EventParticipantRoleEnum::PARTICIPANT,
                'status' => $status,
                'participation_roles' => ['player'],
                'confirmation_version' => $event->participation_confirmation_version,
            ]);
        }
        $delete = route('events.lifecycle.delete', $event->routeIdentifier());
        $restore = route('events.lifecycle.restore', $event->routeIdentifier());
        $statusUrl = route('events.lifecycle.show', $event->routeIdentifier());
        $reason = 'Изменились планы организатора';
        $restoreReason = 'Удалось найти подходящее время';

        $this->actingAs($going)->get(route('events.show', $event->routeIdentifier()))
            ->assertOk()->assertDontSee('data-event-lifecycle-open', false);
        $this->postJson($delete, ['reason' => $reason])->assertForbidden();

        $this->actingAs($owner)->get(route('events.show', $event->routeIdentifier()))
            ->assertOk()->assertSee('Удалить мероприятие');
        $this->postJson($delete, ['reason' => '  коротко  '])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->post($delete, ['reason' => '  '.$reason.'  '])->assertRedirect($statusUrl);
        $this->assertSoftDeleted('events', ['id' => $event->id]);
        $this->assertDatabaseHas('event_lifecycle_changes', ['event_id' => $event->id, 'action' => 'deleted', 'reason' => $reason]);
        $this->get($statusUrl)->assertOk()->assertSee('Восстановить мероприятие')->assertSee($reason);
        $this->get(route('account.events.deleted'))->assertOk()->assertSee($event->title);
        $this->post($delete, ['reason' => $reason])->assertRedirect($statusUrl);

        $notifications = fn () => DB::table('user_notifications')->where('payload->source', 'event.lifecycle')
            ->where('payload->event_id', $event->id);
        $this->assertSame(2, $notifications()->count());
        $this->assertEqualsCanonicalizing([$going->id, $thinking->id], $notifications()->pluck('user_id')->all());
        $this->assertStringContainsString($reason, $notifications()->first()->body);

        $this->actingAs($going)->get($statusUrl)->assertOk()->assertSee($reason)->assertDontSee('Восстановить мероприятие');
        $this->postJson($restore, ['reason' => $restoreReason])->assertForbidden();
        $this->actingAs($declined)->get($statusUrl)->assertNotFound();

        $this->actingAs($owner)->postJson($restore, ['reason' => '  мало  '])->assertUnprocessable();
        $this->post($restore, ['reason' => $restoreReason])->assertRedirect(route('events.show', $event->routeIdentifier()));
        $this->assertNotSoftDeleted('events', ['id' => $event->id]);
        $this->assertDatabaseHas('event_lifecycle_changes', ['event_id' => $event->id, 'action' => 'restored', 'reason' => $restoreReason]);
        $this->assertSame(4, $notifications()->count());
        $this->assertSame(4, $event->participants()->count());
        $this->post($restore, ['reason' => $restoreReason])->assertRedirect();
        $this->assertSame(4, $notifications()->count());
    }

    public function test_deleting_and_restoring_event_keeps_active_booking_and_slot(): void
    {
        $owner = User::factory()->withVerifiedContact()->create(['status' => UserStatusEnum::CONFIRMED]);
        $venue = $this->venue();
        $start = CarbonImmutable::now('Europe/Moscow')->addDays(2)->setTime(19, 0);
        $event = $this->createEvent($owner, $venue, [
            'slots' => [['starts_at' => $start->format('Y-m-d\\TH:i'), 'duration_minutes' => 90]],
        ]);
        $booking = $event->booking()->create([
            'venue_id' => $venue->id,
            'created_by_actor_id' => $event->organizer_actor_id,
            'status' => VenueBookingStatusEnum::CONFIRMED,
            'scope' => VenueBookingScopeEnum::WHOLE,
            'starts_at' => $event->starts_at,
            'ends_at' => $event->ends_at,
        ]);
        $identifier = $event->routeIdentifier();
        $this->get(route('events.show', $identifier))->assertOk()
            ->assertSee('К данному мероприятию привязана бронь')
            ->assertSee($venue->name)->assertSee('19:00')->assertSee('Всё равно удалить');
        $this->post(route('events.lifecycle.delete', $identifier), ['reason' => 'Нужно перенести мероприятие'])->assertRedirect();
        $this->assertSoftDeleted('events', ['id' => $event->id]);
        $this->assertSame($event->id, $booking->fresh()->event_id);
        $this->assertSame(VenueBookingStatusEnum::CONFIRMED, $booking->fresh()->status);
        $this->assertTrue($booking->fresh()->status->occupiesVenue());
        $this->post(route('events.lifecycle.restore', $identifier), ['reason' => 'Мероприятие всё же состоится'])->assertRedirect();
        $this->assertNotSoftDeleted('events', ['id' => $event->id]);
        $this->assertSame($booking->id, $event->fresh()->booking->id);
        $this->assertSame(VenueBookingStatusEnum::CONFIRMED, $booking->fresh()->status);

        $this->post(route('events.lifecycle.delete', $identifier), ['reason' => 'Планы снова изменились'])->assertRedirect();
        DB::table('venue_bookings')->where('id', $booking->id)->update(['status' => 'cancelled']);
        $this->post(route('events.lifecycle.restore', $identifier), ['reason' => 'Найдём другое бронирование'])->assertRedirect();
        $this->assertNotSoftDeleted('events', ['id' => $event->id]);
        $this->assertNull($event->fresh()->booking);
        $this->assertNull($booking->fresh()->event_id);
        $this->assertSame(VenueBookingStatusEnum::CANCELLED, $booking->fresh()->status);
    }

    public function test_finished_event_report_and_feedback_settings_permissions(): void
    {
        $owner = User::factory()->withVerifiedContact()->create(['status'=>UserStatusEnum::CONFIRMED]);
        $event = $this->createEvent($owner, $this->venue(), ['discussion_enabled'=>false,'report_comments_enabled'=>true]);
        $this->assertFalse($event->discussion_enabled);
        $event->forceFill(['status'=>EventStatusEnum::COMPLETED,'starts_at'=>now()->subHours(3),'ends_at'=>now()->subHour()])->save();
        $url = route('events.show',$event->routeIdentifier());
        $this->get($url)->assertOk()->assertSee('Завершено')->assertSee('data-report-reaction="like"',false)
            ->assertSee('data-report-like-count',false)->assertSee('data-event-report-wall',false)
            ->assertSee('placeholder="Написать сообщение"', false)
            ->assertSee('placeholder="Написать комментарий"', false)
            ->assertDontSee('data-event-participate aria-haspopup',false);
        $this->post(route('events.report.store',$event->routeIdentifier()),['result_description'=>'Отличная игра'])->assertRedirect();
        $this->get($url)->assertOk()->assertSee('Отличная игра');
        $settings=route('events.feedback.settings',$event->routeIdentifier());
        $this->patch($settings,['discussion_enabled'=>true,'report_comments_enabled'=>false])->assertRedirect();
        $this->assertFalse($event->fresh()->report_comments_enabled);
        $this->actingAs(User::factory()->withVerifiedContact()->create(['status'=>UserStatusEnum::CONFIRMED]))
            ->patchJson($settings,['discussion_enabled'=>true,'report_comments_enabled'=>true])->assertForbidden();
        $this->postJson(route('events.report.store',$event->routeIdentifier()),['result_description'=>'Чужой отчёт'])->assertForbidden();
        $this->assertSame('Отличная игра',$event->fresh()->result_description);
    }
}
