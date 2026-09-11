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

    @unless($bookingPolicy ?? null)
        <div class="account-venue-schedule__pricing-hint mb-4">
            Чтобы задавать разные цены по времени, сначала опубликуйте условия аренды.
        </div>
    @endunless

    <div class="account-venue-schedule__days">
        @foreach($weekDays as $dayOfWeek => $dayLabel)
            <section class="account-venue-schedule-day" data-venue-schedule-day data-day-of-week="{{ $dayOfWeek }}" data-day-label="{{ $dayLabel }}">
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
                            <div class="account-venue-schedule-interval__actions">
                                <button type="button" class="btn btn--secondary btn--sm account-venue-schedule-interval__remove" data-venue-schedule-remove-interval>Удалить</button>
                                <button
                                    type="button"
                                    class="btn btn--secondary btn--sm account-venue-schedule-interval__prices"
                                    data-venue-prices-open
                                    @unless($bookingPolicy ?? null) disabled title="Сначала опубликуйте условия аренды" @endunless
                                >Цены</button>
                            </div>
                        </div>
                        @error("intervals.$dayOfWeek.$index.starts_at")<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        @error("intervals.$dayOfWeek.$index.ends_at")<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    @endforeach
                </div>
                <button type="button" class="btn btn--secondary btn--sm account-venue-schedule-day__add" data-venue-schedule-add-interval>Добавить интервал</button>
            </section>
        @endforeach
    </div>

    @if($bookingPolicy ?? null)
        @php $priceInputIndex = 0; @endphp
        <dialog
            class="account-venue-price-dialog"
            data-venue-price-dialog
            data-time-step="{{ $bookingPolicy->time_step_minutes }}"
            data-next-index="{{ collect($slotPriceRows ?? [])->flatten(1)->count() }}"
            data-whole-placeholder="{{ number_format($bookingPolicy->whole_price_per_step_minor / 100, 2, ',', ' ') }}"
            data-half-placeholder="{{ $bookingPolicy->half_price_per_step_minor === null ? '—' : number_format($bookingPolicy->half_price_per_step_minor / 100, 2, ',', ' ') }}"
        >
            <div class="account-venue-price-dialog__panel">
                <div class="account-venue-price-dialog__head">
                    <div>
                        <div class="account-venue-price-dialog__eyebrow">Стоимость по времени</div>
                        <h2 data-venue-price-dialog-title>Цены</h2>
                        <p>
                            Цена указана за один шаг {{ $bookingPolicy->time_step_minutes }} мин.
                            Пустое поле использует базовую стоимость из условий аренды.
                        </p>
                    </div>
                    <button type="button" class="account-venue-price-dialog__close" data-venue-price-dialog-close aria-label="Закрыть">×</button>
                </div>

                <div class="account-venue-price-dialog__toolbar">
                    <button type="button" class="btn btn--secondary btn--sm" data-venue-price-apply-interval>Применить к интервалу</button>
                    <button type="button" class="btn btn--secondary btn--sm" data-venue-price-apply-day>Применить ко дню</button>
                    <button type="button" class="btn btn--secondary btn--sm" data-venue-price-apply-week>Применить к неделе</button>
                    <button type="button" class="btn btn--secondary btn--sm" data-venue-price-reset-interval>Сбросить интервал</button>
                </div>

                <div class="account-venue-price-dialog__grid" data-venue-price-list>
                    @foreach($slotPriceRows ?? [] as $dayOfWeek => $rows)
                        @foreach($rows as $priceRow)
                            <div
                                class="account-venue-slot-price"
                                data-venue-price-row
                                data-day-of-week="{{ $dayOfWeek }}"
                                data-starts-at="{{ $priceRow['starts_at'] }}"
                                hidden
                            >
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
                                @error("slot_prices.$priceInputIndex.whole_price")<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                                @error("slot_prices.$priceInputIndex.half_price")<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                            </div>
                            @php $priceInputIndex++; @endphp
                        @endforeach
                    @endforeach
                </div>

                <div class="account-venue-price-dialog__empty" data-venue-price-empty hidden>
                    Для этого интервала пока нет ценовых шагов. Проверьте время начала и конца.
                </div>

                <div class="account-venue-price-dialog__footer">
                    <button type="button" class="btn btn--primary btn--sm" data-venue-price-dialog-close>Готово</button>
                </div>
            </div>
        </dialog>
    @endif

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