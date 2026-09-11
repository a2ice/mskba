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

    <section class="account-venue-schedule__pricing mt-5" data-venue-slot-pricing>
        <div class="account-venue-schedule__section-head">
            <div>
                <h2>Стоимость по времени</h2>
                @if($bookingPolicy ?? null)
                    <p>Индивидуальная цена за один шаг {{ $bookingPolicy->time_step_minutes }} мин. Пустая ячейка использует общую стоимость из условий аренды.</p>
                @else
                    <p>Сначала опубликуйте условия аренды — после этого здесь появятся ценовые ячейки.</p>
                @endif
            </div>
            @if(($bookingPolicy ?? null) && ($slotPriceRows ?? []) !== [])
                <button type="button" class="btn btn--secondary btn--sm" data-venue-price-apply-week>Применить выбранную цену ко всей неделе</button>
            @endif
        </div>

        @if(($bookingPolicy ?? null) && ($slotPriceRows ?? []) !== [])
            @php $priceInputIndex = 0; @endphp
            <div class="account-venue-slot-pricing__days">
                @foreach($weekDays as $dayOfWeek => $dayLabel)
                    @if(!empty($slotPriceRows[$dayOfWeek]))
                        <section class="account-venue-slot-pricing-day" data-venue-price-day>
                            <div class="account-venue-slot-pricing-day__head">
                                <h3>{{ $dayLabel }}</h3>
                                <button type="button" class="btn btn--secondary btn--sm" data-venue-price-apply-day>Применить выбранную цену ко всему дню</button>
                            </div>
                            <div class="account-venue-slot-pricing-day__grid">
                                @foreach($slotPriceRows[$dayOfWeek] as $priceRow)
                                    <div class="account-venue-slot-price" data-venue-price-row>
                                        <strong>{{ $priceRow['starts_at'] }}–{{ $priceRow['ends_at'] }}</strong>
                                        <input type="hidden" name="slot_prices[{{ $priceInputIndex }}][day_of_week]" value="{{ $dayOfWeek }}">
                                        <input type="hidden" name="slot_prices[{{ $priceInputIndex }}][starts_at]" value="{{ $priceRow['starts_at'] }}">
                                        <label>
                                            <span>Весь зал</span>
                                            <input class="form-control" inputmode="decimal" name="slot_prices[{{ $priceInputIndex }}][whole_price]" value="{{ old("slot_prices.$priceInputIndex.whole_price", $priceRow['whole_price']) }}" placeholder="{{ number_format($bookingPolicy->whole_price_per_step_minor / 100, 2, ',', ' ') }}">
                                        </label>
                                        <label>
                                            <span>Половина</span>
                                            <input class="form-control" inputmode="decimal" name="slot_prices[{{ $priceInputIndex }}][half_price]" value="{{ old("slot_prices.$priceInputIndex.half_price", $priceRow['half_price']) }}" placeholder="{{ $bookingPolicy->half_price_per_step_minor === null ? '—' : number_format($bookingPolicy->half_price_per_step_minor / 100, 2, ',', ' ') }}">
                                        </label>
                                        <button type="button" class="account-venue-slot-price__select" data-venue-price-select aria-label="Выбрать цену {{ $priceRow['starts_at'] }}–{{ $priceRow['ends_at'] }}"></button>
                                    </div>
                                    @error("slot_prices.$priceInputIndex.whole_price")<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                                    @error("slot_prices.$priceInputIndex.half_price")<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                                    @php $priceInputIndex++; @endphp
                                @endforeach
                            </div>
                        </section>
                    @endif
                @endforeach
            </div>
        @elseif($bookingPolicy ?? null)
            <div class="alert alert-info">Сохраните часы работы — ценовые ячейки будут построены по ним автоматически.</div>
        @endif
    </section>

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
