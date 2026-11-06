@php
    $calendarPrices = $calendarPrices ?? collect();
    $calendarId = $calendarId ?? 'venue';
    $currency = $calendarCurrency ?? 'RUB';
    $formatter = app(\App\Modules\VenueBooking\Application\Services\MinorAmountParser::class);
@endphp
<section class="panel app-rental-pricing__panel" aria-labelledby="{{ $calendarId }}-title">
    <h2 id="{{ $calendarId }}-title">Цены по датам</h2>
    @if($calendarPrices->isNotEmpty())
        <div class="app-rental-pricing__table-wrap">
            <table class="app-rental-pricing__table">
                <thead><tr><th scope="col">Дата</th><th scope="col">Время</th><th scope="col">Весь зал</th><th scope="col">Половина</th><th scope="col"><span class="sr-only">Действия</span></th></tr></thead>
                <tbody>
                    @foreach($calendarPrices as $price)
                        <tr>
                            <td>{{ $price->date->format('d.m.Y') }}</td>
                            <td>{{ substr((string) $price->starts_at, 0, 5) }}</td>
                            <td>{{ $price->whole_price_per_step_minor === null ? 'Наследуется' : $formatter->format((int) $price->whole_price_per_step_minor, $currency) }}</td>
                            <td>{{ $price->half_price_per_step_minor === null ? 'Наследуется' : $formatter->format((int) $price->half_price_per_step_minor, $currency) }}</td>
                            <td>
                                <form method="POST" action="{{ str_replace('__PRICE__', (string) $price->id, $calendarDeleteUrl) }}">
                                    @csrf @method('DELETE')
                                    <button class="button secondary" type="submit" aria-label="Удалить цену {{ $price->date->format('d.m.Y') }} {{ substr((string) $price->starts_at, 0, 5) }}">Удалить</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
    <form method="POST" action="{{ $calendarStoreUrl }}" class="app-rental-pricing__form">
        @csrf
        <div class="app-rental-pricing__grid">
            <label class="field-group" for="{{ $calendarId }}-date">
                <span>Дата</span><input id="{{ $calendarId }}-date" type="date" name="date" value="{{ old('date') }}" required>
            </label>
            <label class="field-group" for="{{ $calendarId }}-starts">
                <span>Время</span><input id="{{ $calendarId }}-starts" type="time" name="starts_at" value="{{ old('starts_at') }}" required step="{{ (int) $calendarStep * 60 }}">
            </label>
            <label class="field-group" for="{{ $calendarId }}-whole">
                <span>Весь зал, {{ $currency }}</span><input id="{{ $calendarId }}-whole" type="text" inputmode="decimal" name="whole_price_per_step" value="{{ old('whole_price_per_step') }}" placeholder="Наследовать">
            </label>
            <label class="field-group" for="{{ $calendarId }}-half">
                <span>Половина, {{ $currency }}</span><input id="{{ $calendarId }}-half" type="text" inputmode="decimal" name="half_price_per_step" value="{{ old('half_price_per_step') }}" placeholder="Наследовать">
            </label>
        </div>
        <div class="app-rental-pricing__actions">
            <button class="button primary" type="submit">Сохранить цену</button>
            <span class="app-rental-pricing__subtle">Цена за {{ (int) $calendarStep }} мин.</span>
        </div>
    </form>
</section>
