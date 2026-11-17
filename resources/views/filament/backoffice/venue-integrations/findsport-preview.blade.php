<div class="space-y-4">
    @if ($error)
        <div class="rounded-lg border border-danger-500/40 p-4 text-sm text-danger-600 dark:text-danger-400">
            {{ $error }}
        </div>
    @elseif ($report)
        <div class="space-y-1 text-sm">
            <p><strong>FindSport #{{ $report['external_id'] }}</strong> · зал #{{ $report['external_court_id'] }} · {{ $report['timezone'] }}</p>
            <p>Данные получены: {{ $report['observed_at'] }} · шаг {{ $report['step_minutes'] }} мин · минимум {{ $report['minimum_minutes'] }} мин</p>
            <p class="text-gray-500 dark:text-gray-400">{{ $report['notice'] }}</p>
        </div>

        <div class="mskba-bo-table mskba-bo-table--cards">
            <table class="mskba-bo-table__table">
                <thead class="border-b border-gray-200 text-left dark:border-gray-700">
                    <tr>
                        <th class="p-2">Дата</th>
                        <th class="p-2">Полнота</th>
                        <th class="p-2">Не занято</th>
                        <th class="p-2">Занято</th>
                        <th class="p-2">Недоступно</th>
                        <th class="p-2">Неизвестно</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($report['days'] as $day)
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td data-label="Дата">{{ $day['date'] }}</td>
                            <td data-label="Полнота">{{ $day['complete'] ? 'Полный ответ' : 'Нет данных' }}</td>
                            <td data-label="Не занято">{{ $day['counts']['available'] }}</td>
                            <td data-label="Занято">{{ $day['counts']['booked'] }}</td>
                            <td data-label="Недоступно">{{ $day['counts']['unavailable'] }}</td>
                            <td data-label="Неизвестно">{{ $day['counts']['unknown'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="text-xs text-gray-500 dark:text-gray-400">«Не занято» означает только отсутствие признака брони в ответе FindSport на момент запроса. Не является гарантией доступности. Цены приведены за 30 минут, в рублях.</p>

        @foreach ($report['days'] as $day)
            <details class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                <summary class="cursor-pointer text-sm font-semibold">{{ $day['date'] }} — {{ $day['counts']['booked'] }} занятых интервалов</summary>
                <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
                    @foreach ($day['slots'] as $slot)
                        @php
                            $statusText = match ($slot['status']) {
                                'booked' => 'Занято',
                                'available' => 'Не занято',
                                'unavailable' => 'Недоступно',
                                default => 'Неизвестно',
                            };
                        @endphp
                        <div class="rounded-md border border-gray-200 p-2 text-xs dark:border-gray-700">
                            <div class="font-medium">{{ $slot['time'] }}–{{ $slot['ends_at'] }}</div>
                            <div>{{ $statusText }}</div>
                            <div>{{ $slot['price_minor'] !== null ? number_format($slot['price_minor'] / 100, 0, ',', ' ').' ₽' : 'Цена не определена' }}</div>
                        </div>
                    @endforeach
                </div>
            </details>
        @endforeach
    @endif
</div>
