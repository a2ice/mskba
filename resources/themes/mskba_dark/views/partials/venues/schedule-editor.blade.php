<form method="POST" action="{{ $action }}" class="account-venue-schedule" data-venue-schedule-form>
    @csrf
    @method('PUT')

    <input type="hidden" name="timezone" value="{{ $venue->schedule?->timezone ?? 'Europe/Moscow' }}">

    <div class="field mb-4">
        <label for="venueOperationalStatus" class="form-label">Состояние площадки</label>
        <select id="venueOperationalStatus" name="operational_status" class="form-select @error('operational_status') is-invalid @enderror">
            @foreach(\App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum::cases() as $operationalStatus)
                <option value="{{ $operationalStatus->value }}" @selected(old('operational_status', $venue->operational_status->value) === $operationalStatus->value)>
                    {{ $operationalStatus->label() }}
                </option>
            @endforeach
        </select>
        @error('operational_status')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="account-venue-schedule__bulk-actions mb-4">
        <button type="button" class="btn btn--secondary btn--sm" data-venue-schedule-apply-all>Применить первый заполненный день ко всем</button>
        <button type="button" class="btn btn--secondary btn--sm" data-venue-schedule-reset-all>Сбросить у всех</button>
    </div>

    <div class="account-venue-schedule__days">
        @foreach($weekDays as $dayOfWeek => $dayLabel)
            <section class="account-venue-schedule-day" data-venue-schedule-day>
                <div class="account-venue-schedule-day__head"><h2>{{ $dayLabel }}</h2><span data-venue-schedule-day-state>Выходной</span></div>
                <div class="account-venue-schedule-day__intervals">
                    @foreach($scheduleRows[$dayOfWeek] as $index => $interval)
                        @php
                            $startsAtValue = old("intervals.$dayOfWeek.$index.starts_at", $interval['starts_at']);
                            $endsAtValue = old("intervals.$dayOfWeek.$index.ends_at", $interval['ends_at']);
                            $isVisibleInterval = $index === 0 || $startsAtValue || $endsAtValue;
                        @endphp
                        <div class="account-venue-schedule-interval" data-venue-schedule-interval @unless($isVisibleInterval) hidden @endunless>
                            <div class="account-venue-schedule-interval__label">Интервал {{ $index + 1 }}</div>
                            <label><span>Начало</span><input type="time" name="intervals[{{ $dayOfWeek }}][{{ $index }}][starts_at]" class="form-control" value="{{ $startsAtValue }}"></label>
                            <label><span>Конец</span><input type="time" name="intervals[{{ $dayOfWeek }}][{{ $index }}][ends_at]" class="form-control" value="{{ $endsAtValue }}"></label>
                            <button type="button" class="btn btn--secondary btn--sm account-venue-schedule-interval__remove" data-venue-schedule-remove-interval>Удалить</button>
                        </div>
                        @error("intervals.$dayOfWeek.$index.starts_at")<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        @error("intervals.$dayOfWeek.$index.ends_at")<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    @endforeach
                </div>
                <button type="button" class="btn btn--secondary btn--sm account-venue-schedule-day__add" data-venue-schedule-add-interval>Добавить интервал</button>
            </section>
        @endforeach
    </div>

    <section class="account-venue-schedule__exceptions mt-5">
        <div class="account-venue-schedule__section-head">
            <div><h2>Исключения по датам</h2><p>Праздники, закрытые дни или часы, отличающиеся от обычной недели.</p></div>
            <button type="button" class="btn btn--secondary btn--sm" data-schedule-exception-add>Добавить дату</button>
        </div>
        <div class="account-venue-schedule__exception-list" data-schedule-exception-list>
            @php $exceptionRows = old('exceptions', $scheduleExceptions ?? []); @endphp
            @foreach($exceptionRows as $exceptionIndex => $exception)
                @include('theme::partials.venues.schedule-exception-row', ['index' => $exceptionIndex, 'exception' => $exception])
            @endforeach
        </div>
        <template data-schedule-exception-template>
            @include('theme::partials.venues.schedule-exception-row', ['index' => '__INDEX__', 'exception' => ['date' => '', 'is_closed' => false, 'intervals' => [['starts_at' => '', 'ends_at' => '']]]])
        </template>
        @error('exceptions')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
    </section>

    <div class="account-venue-schedule__actions mt-4">
        <a href="{{ $cancelUrl }}" class="btn btn--secondary btn--sm">{{ $cancelLabel ?? 'К площадке' }}</a>
        <button type="submit" class="btn btn--primary btn--sm">Сохранить расписание</button>
    </div>
</form>
