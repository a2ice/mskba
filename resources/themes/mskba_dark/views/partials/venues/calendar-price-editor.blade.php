@php
    $calendarPrices = $calendarPrices ?? collect();
    $calendarStep = (int) ($calendarStep ?? 30);
    $calendarCurrency = $calendarCurrency ?? 'RUB';
    $calendarId = $calendarId ?? 'venue';
@endphp

<div class="card mt-4" data-calendar-price-editor>
    <div class="card-body">
        <h2 class="h5 mb-3">Цены по календарным датам</h2>
        <p class="text-muted">Исключения действуют только в выбранную дату. Пустая цена наследуется из регулярного тарифа. Стоимость указана за {{ $calendarStep }} мин ({{ $calendarCurrency }}).</p>

        @if($calendarPrices->isNotEmpty())
            <div class="d-flex flex-column gap-2 mb-4">
                @foreach($calendarPrices as $calendarPrice)
                    <div class="d-flex flex-wrap gap-3 align-items-center justify-content-between">
                        <div>
                            <strong>{{ $calendarPrice->date->format('d.m.Y') }}, {{ substr((string) $calendarPrice->starts_at, 0, 5) }}</strong>
                            <span class="text-muted">
                                · Весь зал: {{ $calendarPrice->whole_price_per_step_minor === null ? 'Наследуется' : $amounts->format($calendarPrice->whole_price_per_step_minor, $calendarCurrency) }}
                                · Половина: {{ $calendarPrice->half_price_per_step_minor === null ? 'Наследуется' : $amounts->format($calendarPrice->half_price_per_step_minor, $calendarCurrency) }}
                            </span>
                        </div>
                        <form method="POST" action="{{ str_replace('__PRICE__', (string) $calendarPrice->id, $calendarDeleteUrl) }}">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn--danger btn--sm" aria-label="Удалить цену {{ $calendarPrice->date->format('d.m.Y') }} {{ substr((string) $calendarPrice->starts_at, 0, 5) }}">Удалить</button>
                        </form>
                    </div>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ $calendarStoreUrl }}">
            @csrf
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label" for="{{ $calendarId }}-date">Дата</label>
                    <input class="form-control" id="{{ $calendarId }}-date" name="date" type="date" value="{{ old('date') }}" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="{{ $calendarId }}-time">Время</label>
                    <input class="form-control" id="{{ $calendarId }}-time" name="starts_at" type="time" value="{{ old('starts_at') }}" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="{{ $calendarId }}-whole">Весь зал</label>
                    <input class="form-control" id="{{ $calendarId }}-whole" name="whole_price_per_step" inputmode="decimal" placeholder="Наследовать" value="{{ old('whole_price_per_step') }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="{{ $calendarId }}-half">Половина</label>
                    <input class="form-control" id="{{ $calendarId }}-half" name="half_price_per_step" inputmode="decimal" placeholder="Наследовать" value="{{ old('half_price_per_step') }}">
                </div>
            </div>
            <button type="submit" class="btn btn--primary btn--sm mt-3">Добавить / обновить цену</button>
        </form>
    </div>
</div>
