@php
    $dayCalendarDates = collect(range(0, 8))->map(
        fn (int $offset) => \Carbon\CarbonImmutable::now('Europe/Moscow')->startOfDay()->addDays($offset)
    );
    $dayCalendarInfo = [];
    if (isset($scheduledDays) && $scheduledDays->isNotEmpty()) {
        foreach ($scheduledDays as $listed) {
            $dayCalendarInfo[$listed['date']] = [
                'hours' => collect($listed['intervals'] ?? [])->map(
                    fn ($hours) => $hours['startsAt'].'–'.($hours['endsAt'] === '23:59' ? '00:00' : $hours['endsAt'])
                )->implode(', '),
                'occupancy' => $listed['occupancyPercent'] ?? null,
            ];
        }
    } else {
        // The quote screen has a real Venue model rather than the public
        // venue DTO. Resolve exactly the same effective hours/occupancy here.
        $calendarVenue = $venue instanceof \App\Modules\Venue\Domain\Models\Venue
            ? $venue : \App\Modules\Venue\Domain\Models\Venue::query()->findOrFail($venue->id);
        $calendarCourt = isset($court) ? $court
            : ($calendarVenue->primaryCourt()->first() ?? $calendarVenue->courts()->first());
        $effectiveHours = app(\App\Modules\Venue\Application\Services\EffectiveVenueHours::class);
        $occupationRows = [];
        foreach ($dayCalendarDates as $optionDate) {
            $intervals = $calendarCourt
                ? $effectiveHours->intervals($calendarVenue, $calendarCourt, $optionDate) : collect();
            $timeRanges = ($intervals ?? collect())->map(fn ($hours): array => [
                'startsAt' => substr((string) $hours->starts_at, 0, 5),
                'endsAt' => substr((string) $hours->ends_at, 0, 5),
            ])->values()->all();
            $occupationRows[] = ['date' => $optionDate->toDateString(), 'intervals' => $timeRanges];
            $dayCalendarInfo[$optionDate->toDateString()] = [
                'hours' => collect($timeRanges)->map(
                    fn ($hours) => $hours['startsAt'].'–'.($hours['endsAt'] === '23:59' ? '00:00' : $hours['endsAt'])
                )->implode(', '),
                'occupancy' => null,
            ];
        }
        $occupation = app(\App\Modules\Venue\Application\Services\VenueScheduleDayOccupancy::class)
            ->forDays($calendarVenue, $calendarCourt, $occupationRows, 'Europe/Moscow');
        foreach ($occupation as $date => $percentage) {
            $dayCalendarInfo[$date]['occupancy'] = $percentage;
        }
    }
@endphp
<div class="app-venue-day-dialog__calendar" aria-label="Выбор даты">
    <button type="button" class="app-venue-day-dialog__nav" data-venue-day-nav="-1"
        aria-label="Предыдущий день" disabled>
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
    </button>
    <div class="app-venue-day-dialog__dates" data-venue-day-dates role="group" aria-label="Доступные даты">
        @foreach($dayCalendarDates as $dayOption)
            @php
                $dayDetail = $dayCalendarInfo[$dayOption->toDateString()] ?? ['hours' => '', 'occupancy' => null];
                $dayOccupancy = $dayDetail['occupancy'];
            @endphp
            <div class="app-venue-day-dialog__date-item" data-day-date="{{ $dayOption->toDateString() }}">
                <button type="button" class="app-venue-day-dialog__date app-venue-schedule__day app-venue-schedule__day--interactive"
                    data-venue-day-date="{{ $dayOption->toDateString() }}"
                    aria-pressed="false" aria-label="{{ $dayOption->locale('ru')->translatedFormat('j F, l') }}">
                    <strong @class(['app-venue-schedule__date', 'is-weekend' => $dayOption->isWeekend()])>{{ $dayOption->locale('ru')->translatedFormat('j M') }}</strong>
                    <span class="app-venue-day-dialog__date-hours">{{ $dayDetail['hours'] ?: 'Не работает' }}</span>
                </button>
                <div @class(['app-venue-schedule__occupancy', 'is-unknown' => $dayOccupancy === null])
                    data-day-occupancy
                    role="img"
                    aria-label="{{ $dayOccupancy === null ? 'Нет данных о занятости' : 'Занято '.$dayOccupancy.'%' }}">
                    <span class="app-venue-schedule__occupancy-booked" data-day-occupancy-booked
                        style="width:{{ $dayOccupancy ?? 0 }}%"></span>
                </div>
            </div>
        @endforeach
    </div>
    <button type="button" class="app-venue-day-dialog__nav" data-venue-day-nav="1"
        aria-label="Следующий день">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
    </button>
</div>
