@php
    $rentalCourts = $venue->courts()->get();
    $initialRentalCourt = $court ?? ($rentalCourts->count() === 1 ? $rentalCourts->first() : null);
    $calendarCourt = $initialRentalCourt ?? $rentalCourts->first();
    $originalStart = $event->starts_at?->setTimezone($timezone);
    $originalEnd = $event->ends_at?->setTimezone($timezone);
@endphp
<dialog class="mskba-modal app-event-dialog" data-event-rental-prompt aria-labelledby="event-rental-prompt-heading"
    data-original-date="{{ $originalStart?->toDateString() }}"
    data-original-label="{{ $originalStart?->locale('ru')->translatedFormat('j F Y · H:i') }}–{{ $originalEnd?->format('H:i') }}"
    data-retry-message="{{ session('event_rental_error') }}">
    <header class="mskba-modal__header">
        <h2 id="event-rental-prompt-heading" data-rental-prompt-title>Аренда</h2>
        <button type="button" class="icon-button" data-event-dialog-close aria-label="Закрыть"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6l-12 12M6 6l12 12"/></svg></button>
    </header>
    <div class="mskba-modal__body mskba-scroll app-event-panel">
        <p data-rental-prompt-message role="status"></p>
        <label class="field-group" data-event-rental-court-field hidden>Зал
            <span class="select-control"><select data-event-rental-court required>
                <option value="">Выбрать зал</option>
                @foreach($rentalCourts as $option)
                    <option value="{{ $option->id }}" @selected($initialRentalCourt?->id === $option->id)>{{ $option->publicName() }}</option>
                @endforeach
            </select></span>
        </label>
        <form method="POST" action="{{ route('events.booking.quote', $event->routeIdentifier()) }}" data-event-rental-original-form hidden>
            @csrf
            <input type="hidden" name="expected_version" value="{{ $event->participation_confirmation_version }}">
            <input type="hidden" name="rental_action" value="select">
            <input type="hidden" name="venue_court_id" value="{{ $initialRentalCourt?->id }}">
            <input type="hidden" name="starts_at" value="{{ $originalStart?->format('Y-m-d\TH:i') }}">
            <input type="hidden" name="duration_minutes" value="{{ $originalStart && $originalEnd ? (int) $originalStart->diffInMinutes($originalEnd) : '' }}">
            <input type="hidden" name="scope" value="{{ $event->planned_booking_scope?->value ?? 'whole' }}">
        </form>
    </div>
    <footer class="mskba-modal__footer" style="flex-wrap:wrap">
        <button type="button" class="button secondary" data-rental-choose-new hidden>Выбрать новую дату</button>
        <button type="button" class="button primary" data-rental-apply-original hidden>Да, применить</button>
        <button type="button" class="button primary" data-event-rental-court-next hidden>Продолжить</button>
    </footer>
</dialog>
<dialog class="app-venue-overlay app-venue-day-dialog" data-venue-day-dialog data-event-rental-calendar
    data-day-url="{{ route('venues.schedule.day', $venue->id) }}"
    data-court-id="{{ $calendarCourt?->id }}" data-court-name="{{ $calendarCourt?->publicName() }}"
    data-event-calendar-court="{{ $calendarCourt?->id }}" data-event-free="{{ !$policy && $venue->hasFreeAccess() ? '1' : '0' }}"
    aria-labelledby="app-venue-day-dialog-title">
    <div class="app-venue-overlay__header">
        <h2 id="app-venue-day-dialog-title" data-venue-day-title>Выбор времени аренды</h2>
        <button type="button" data-venue-overlay-close aria-label="Закрыть"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M5 5L19 19M19 5L5 19"/></svg></button>
    </div>
    @include('theme::pages.venues.partials.day-calendar', ['court' => $calendarCourt])
    <p class="app-venue-day-dialog__status" role="status" aria-live="polite" data-venue-day-status hidden></p>
    <div class="app-venue-day-dialog__grid" data-venue-day-slots aria-label="Интервалы бронирования"></div>
    <form method="POST" action="{{ route('events.booking.quote', $event->routeIdentifier()) }}" class="app-venue-day-dialog__footer" data-venue-day-booking-form>
        @csrf
        <input type="hidden" name="expected_version" value="{{ $event->participation_confirmation_version }}">
        <input type="hidden" name="rental_action" value="select">
        <input type="hidden" name="venue_court_id" data-venue-day-court>
        <input type="hidden" name="starts_at" data-venue-day-start>
        <input type="hidden" name="duration_minutes" data-venue-day-duration>
        <input type="hidden" name="scope" value="whole">
        <div class="app-venue-day-dialog__selection">
            <strong data-venue-day-summary>Выберите время</strong>
            <span data-venue-day-minimum></span>
            <span>Весь зал</span>
        </div>
        <button type="submit" class="button primary" data-venue-day-book disabled>Выбрать это время</button>
    </form>
</dialog>
@include('theme::pages.venues.partials.day-event-dialog')
