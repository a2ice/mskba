<div class="space-y-3">
    @if(! $policy)
        <p class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800">
            У площадки пока нет опубликованных базовых условий аренды. Сначала настройте базовые тарифы в разделе «Аренда» карточки площадки; после этого здесь можно будет задавать диапазоны.
        </p>
    @else
        <p class="text-sm text-gray-600 dark:text-gray-300">
            @if($court)
                Тарифы зала «{{ $court->publicName() }}». Пустое поле цены наследует соответствующую цену площадки;
                отдельно заданная цена зала имеет приоритет. Сброс ручной цены возвращает наследование
                либо тариф внешней интеграции, если он существует.
            @else
                Общий тариф площадки. Залы без собственных цен наследуют значения из этой сетки.
                Сброс диапазона возвращает базовую цену аренды площадки.
            @endif
            <span class="font-medium">Шаг: {{ $step }} мин, валюта {{ $policy->currency }}.</span>
        </p>
        <details class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
            <summary class="cursor-pointer font-medium">Текущие тарифы по дням недели (раскрыть)</summary>
            <div class="mt-3 space-y-3">
                @foreach($rows as $day)
                    <div>
                        <p class="mb-1 text-sm font-semibold">{{ $day['label'] }}</p>
                        <div class="flex flex-wrap gap-2">
                            @foreach($day['ranges'] as $range)
                                <div class="rounded-lg border border-gray-200 px-3 py-2 text-xs dark:border-gray-700">
                                    <div class="font-medium">{{ $range['start'] }}–{{ $range['end'] }}</div>
                                    <div>
                                        Весь: {{ number_format($range['whole'] / 100, 2, ',', ' ') }} ·
                                        Половина: {{ number_format($range['half'] / 100, 2, ',', ' ') }}
                                        {{ $policy->currency }}
                                    </div>
                                    <div class="text-gray-500 dark:text-gray-400">{{ $range['source'] }}</div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </details>
        <p class="text-sm font-medium">
            Минимальная стоимость одного шага площадки: {{ app(\App\Modules\VenueBooking\Application\Services\MinorAmountParser::class)->format((int) $policy->whole_price_per_step_minor, $policy->currency) }} {{ $policy->currency }} за {{ $policy->time_step_minutes }} мин.
            Цена ниже минимума не сохранится; при необходимости измените базовый минимум на вкладке «Аренда».
        </p>
        <p class="text-xs text-gray-500 dark:text-gray-400">
            Ниже загружены уже сохранённые диапазоны. Можно изменить их, добавить новые или удалить: сохранение синхронизирует именно список строк.
        </p>
        <p class="text-xs text-gray-500 dark:text-gray-400">
            Ниже можно назначить несколько диапазонов одной операцией. Временные диапазоны не должны перекрываться в пределах одного дня.
            Для точечной корректировки задайте диапазон длиной в один шаг или добавьте исключение.
        </p>
    @endif
</div>
