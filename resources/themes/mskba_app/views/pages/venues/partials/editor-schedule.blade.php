@php
    $schedule = $venue->schedule;
    $weekDays = [1 => 'Понедельник', 2 => 'Вторник', 3 => 'Среда', 4 => 'Четверг', 5 => 'Пятница', 6 => 'Суббота', 7 => 'Воскресенье'];
@endphp
<form method="POST" action="{{ route('account.venues.schedule.update', $route) }}" class="app-venue-editor__form"
    data-venue-schedule-form>
    @csrf @method('PUT')
    <section class="panel app-venue-editor__panel">
        <h2>Расписание на неделю</h2>
        <div class="app-venue-editor__grid">
            <label class="app-venue-editor__field">Часовой пояс
                <input name="timezone" type="text" required maxlength="64" value="{{ old('timezone', $schedule?->timezone ?? 'Europe/Moscow') }}">
            </label>
            <label class="app-venue-editor__field">Состояние площадки
                <span class="select-control">
                    <select name="operational_status">
                    @foreach(\App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum::cases() as $item)
                        <option value="{{ $item->value }}" @selected(old('operational_status', $venue->operational_status->value) === $item->value)>{{ $item->label() }}</option>
                    @endforeach
                </select>
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                </span>
            </label>
        </div>
        <div class="app-venue-editor__week">
            @foreach($weekDays as $day => $dayLabel)
                <section class="app-venue-editor__day" aria-label="{{ $dayLabel }}" data-venue-schedule-day="{{ $day }}">
                    <div class="app-venue-editor__day-title"><h3>{{ $dayLabel }}</h3>
                        <button type="button" class="app-venue-editor__link" data-venue-schedule-add="{{ $day }}">Добавить интервал</button>
                    </div>
                    <div data-venue-schedule-intervals="{{ $day }}">
                        @php($intervals = $schedule?->intervals?->where('day_of_week', $day)->values() ?? collect())
                        @foreach($intervals as $index => $interval)
                            <div class="app-venue-editor__time-range">
                                <label>С <input type="time" name="intervals[{{ $day }}][{{ $index }}][starts_at]"
                                    value="{{ substr((string) $interval->starts_at,0,5) }}"></label>
                                <label>До <input type="time" name="intervals[{{ $day }}][{{ $index }}][ends_at]"
                                    value="{{ substr((string) $interval->ends_at,0,5) }}"></label>
                                <button type="button" class="app-venue-editor__link" data-venue-schedule-remove aria-label="Удалить интервал">Удалить</button>
                            </div>
                        @endforeach
                    </div>
                    @if($intervals->isEmpty())
                        <p class="app-venue-editor__muted" data-venue-no-hours>Интервалы не указаны</p>
                    @endif
                </section>
            @endforeach
        </div>
    </section>
    <section class="panel app-venue-editor__panel">
        <div class="app-venue-editor__panel-head"><h2>Исключения по датам</h2>
            <button class="button secondary" type="button" data-venue-exception-add>Добавить дату</button>
        </div>
        <div class="app-venue-editor__exceptions" data-venue-exceptions>
            @foreach(($schedule?->exceptions ?? collect()) as $index => $exception)
                <div class="app-venue-editor__exception" data-venue-exception>
                    <label class="app-venue-editor__field">Дата
                        <input type="date" name="exceptions[{{ $index }}][date]" required value="{{ $exception->date?->format('Y-m-d') }}">
                    </label>
                    <label class="check-label app-venue-editor__field--check">
                        <input type="hidden" name="exceptions[{{ $index }}][is_closed]" value="0">
                        <input type="checkbox" name="exceptions[{{ $index }}][is_closed]" value="1" @checked($exception->is_closed) data-venue-exception-closed>
                        Закрыта весь день
                    </label>
                    <div data-venue-exception-hours>
                        @foreach($exception->intervals as $timeIndex => $interval)
                            <div class="app-venue-editor__time-range">
                                <label>С <input type="time" name="exceptions[{{ $index }}][intervals][{{ $timeIndex }}][starts_at]"
                                    value="{{ substr((string) $interval->starts_at,0,5) }}"></label>
                                <label>До <input type="time" name="exceptions[{{ $index }}][intervals][{{ $timeIndex }}][ends_at]"
                                    value="{{ substr((string) $interval->ends_at,0,5) }}"></label>
                                <button type="button" class="app-venue-editor__link" data-venue-schedule-remove>Удалить</button>
                            </div>
                        @endforeach
                    </div>
                    <button type="button" class="app-venue-editor__link" data-venue-exception-add-time>Добавить интервал</button>
                    <button type="button" class="app-venue-editor__link" data-venue-exception-remove>Убрать дату</button>
                </div>
            @endforeach
        </div>
    </section>
    <div class="app-venue-editor__actions"><button type="submit" class="button primary">Сохранить расписание</button></div>
    <template data-venue-week-template>
        <div class="app-venue-editor__time-range">
            <label>С <input type="time" name="intervals[__DAY__][__INDEX__][starts_at]"></label>
            <label>До <input type="time" name="intervals[__DAY__][__INDEX__][ends_at]"></label>
            <button type="button" class="app-venue-editor__link" data-venue-schedule-remove>Удалить</button>
        </div>
    </template>
    <template data-venue-exception-template>
        <div class="app-venue-editor__exception" data-venue-exception>
            <label class="app-venue-editor__field">Дата
                <input type="date" name="exceptions[__EX__][date]" required>
            </label>
            <label class="check-label app-venue-editor__field--check">
                <input type="hidden" name="exceptions[__EX__][is_closed]" value="0">
                <input type="checkbox" name="exceptions[__EX__][is_closed]" value="1" data-venue-exception-closed>Закрыта весь день
            </label>
            <div data-venue-exception-hours></div>
            <button type="button" class="app-venue-editor__link" data-venue-exception-add-time>Добавить интервал</button>
            <button type="button" class="app-venue-editor__link" data-venue-exception-remove>Убрать дату</button>
        </div>
    </template>
    <template data-venue-exception-time-template>
        <div class="app-venue-editor__time-range">
            <label>С <input type="time" name="exceptions[__EX__][intervals][__INDEX__][starts_at]"></label>
            <label>До <input type="time" name="exceptions[__EX__][intervals][__INDEX__][ends_at]"></label>
            <button type="button" class="app-venue-editor__link" data-venue-schedule-remove>Удалить</button>
        </div>
    </template>
</form>
