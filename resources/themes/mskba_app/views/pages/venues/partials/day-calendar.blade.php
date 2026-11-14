@php
    // Only the server-supported nine-day window is navigable here.
    $dayCalendarDates = collect(range(0, 8))->map(
        fn (int $offset) => \Carbon\CarbonImmutable::now('Europe/Moscow')->startOfDay()->addDays($offset)
    );
@endphp
<div class="app-venue-day-dialog__calendar" aria-label="Выбор даты">
    <button type="button" class="app-venue-day-dialog__nav" data-venue-day-nav="-1"
        aria-label="Предыдущий день" disabled>
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
    </button>
    <div class="app-venue-day-dialog__dates" data-venue-day-dates role="group" aria-label="Доступные даты">
        @foreach($dayCalendarDates as $dayOption)
            <button type="button" class="app-venue-day-dialog__date app-venue-schedule__day app-venue-schedule__day--interactive"
                data-venue-day-date="{{ $dayOption->toDateString() }}"
                aria-pressed="false" aria-label="{{ $dayOption->locale('ru')->translatedFormat('j F, l') }}">
                <strong @class(['app-venue-schedule__date', 'is-weekend' => $dayOption->isWeekend()])>{{ $dayOption->locale('ru')->translatedFormat('j M') }}</strong>
                <span class="app-venue-day-dialog__date-hours">Выбрать день</span>
            </button>
        @endforeach
    </div>
    <button type="button" class="app-venue-day-dialog__nav" data-venue-day-nav="1"
        aria-label="Следующий день">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
    </button>
</div>
