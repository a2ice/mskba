@php
    $rangeData = $rangeData ?? [];
    $selectedDays = array_map('intval', (array) ($rangeData['days'] ?? [1, 2, 3, 4, 5]));
    $rangeStart = (string) ($rangeData['starts_at'] ?? '07:00');
    $rangeEnd = (string) ($rangeData['ends_at'] ?? '16:00');
    $rangeOperation = (string) ($rangeData['operation'] ?? 'set');
@endphp
<div class="venue-tariff-grid__panel venue-tariff-grid__range" data-tariff-range-item>
    <div class="venue-tariff-grid__actions" style="justify-content:space-between;align-items:center">
        <h4>Диапазон {{ is_numeric($index) ? ((int) $index + 1) : '__NUMBER__' }}</h4>
        <button class="button secondary" type="button" data-tariff-remove-range>Убрать диапазон</button>
    </div>
    @if($mode === 'weekly')
        <fieldset>
            <legend>Дни действия тарифа</legend>
            <div class="venue-tariff-grid__days">
                @foreach([1=>'Пн',2=>'Вт',3=>'Ср',4=>'Чт',5=>'Пт',6=>'Сб',7=>'Вс'] as $number=>$name)
                    <label>
                        <input type="checkbox" name="ranges[{{ $index }}][days][]" value="{{ $number }}" @checked(in_array($number, $selectedDays, true))>
                        {{ $name }}
                    </label>
                @endforeach
            </div>
        </fieldset>
    @endif
    <div class="venue-tariff-grid__fields">
        <label>Начало диапазона
            <input type="time" name="ranges[{{ $index }}][starts_at]"
                value="{{ $rangeStart }}" required step="{{ $step * 60 }}">
        </label>
        <label>Конец диапазона
            <input type="time" name="ranges[{{ $index }}][ends_at]"
                value="{{ $rangeEnd }}" required step="{{ $step * 60 }}">
        </label>
        <label>Весь зал, {{ $policy->currency }} / шаг
            <input name="ranges[{{ $index }}][whole_price_per_step]" inputmode="decimal" placeholder="Наследовать" value="{{ $rangeData['whole_price_per_step'] ?? '' }}">
        </label>
        <label>Половина, {{ $policy->currency }} / шаг
            <input name="ranges[{{ $index }}][half_price_per_step]" inputmode="decimal" placeholder="Наследовать" value="{{ $rangeData['half_price_per_step'] ?? '' }}">
        </label>
        <label>Действие
            <select name="ranges[{{ $index }}][operation]">
                <option value="set" @selected($rangeOperation === 'set')>Установить цену</option>
                <option value="clear" @selected($rangeOperation === 'clear')>Наследовать — очистить ручную цену</option>
            </select>
        </label>
    </div>
    <h4>Исключения внутри диапазона</h4>
    <div class="venue-tariff-grid__exceptions" data-tariff-exceptions></div>
    <button class="button secondary" type="button" data-tariff-add-exception>Добавить исключение</button>
    <template data-tariff-exception-template>
        <div class="venue-tariff-grid__exception" data-tariff-exception>
            <label>Начало шага
                <input name="ranges[{{ $index }}][exceptions][__EXCEPTION__][starts_at]" type="time" step="{{ $step * 60 }}">
            </label>
            @if($mode === 'weekly')
                <label>День
                    <select name="ranges[{{ $index }}][exceptions][__EXCEPTION__][day_of_week]">
                        <option value="">Все выбранные дни</option>
                        @foreach([1=>'Пн',2=>'Вт',3=>'Ср',4=>'Чт',5=>'Пт',6=>'Сб',7=>'Вс'] as $number=>$name)
                            <option value="{{ $number }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
            <label>Весь зал
                <input name="ranges[{{ $index }}][exceptions][__EXCEPTION__][whole_price_per_step]" inputmode="decimal">
            </label>
            <label>Половина
                <input name="ranges[{{ $index }}][exceptions][__EXCEPTION__][half_price_per_step]" inputmode="decimal">
            </label>
            <label>Действие
                <select name="ranges[{{ $index }}][exceptions][__EXCEPTION__][operation]">
                    <option value="set">Изменить</option>
                    <option value="clear">Вернуть источник</option>
                </select>
            </label>
            <button class="button secondary" type="button" data-tariff-remove-exception>Убрать</button>
        </div>
    </template>
</div>
