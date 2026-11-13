<div class="space-y-4">
    @if (! $latest)
        <p class="text-sm">Сохранённых снимков пока нет. Используйте действие «Сохранить снимок» у источника.</p>
    @else
        @php
            $grouped = $latest->slots->groupBy(fn ($slot) => $slot->local_date->format('Y-m-d'));
            $diagnostics = $latest->diagnostic['days'] ?? [];
        @endphp
        <div class="space-y-1 text-sm">
            <p><strong>Последний снимок #{{ $latest->id }}</strong> · {{ $latest->provider }} · внешний зал #{{ $latest->external_court_id }}</p>
            <p>Получен {{ $latest->observed_at->setTimezone($latest->timezone)->format('d.m.Y H:i:s') }} ({{ $latest->timezone }})</p>
            <p>Источник #{{ $latest->venue_integration_source_id }} · зал MSKBA #{{ $latest->venue_court_id }} · шаг {{ $latest->step_minutes }} мин · минимальная аренда {{ $latest->minimum_minutes }} мин</p>
            <p>
                <strong>{{ $latest->isStale() ? 'Снимок устарел (TTL истёк)' : 'Снимок в пределах TTL' }}</strong>
                · {{ $latest->complete ? 'Покрытие полное' : 'Покрытие неполное' }}
                · {{ count($grouped) }} дней
            </p>
            <p>Информация наблюдательная. Статус «Не занято» не гарантирует возможность бронирования; тарифы, брони и платежи MSKBA этим снимком не изменяются.</p>
        </div>

        <div class="mskba-bo-table mskba-bo-table--cards">
            <table class="mskba-bo-table__table">
                <thead>
                    <tr>
                        <th>Дата</th><th>Полнота</th><th>Не занято</th><th>Занято</th><th>Недоступно</th><th>Неизвестно</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($diagnostics as $date => $day)
                        <tr>
                            <td data-label="Дата">{{ $date }}</td>
                            <td data-label="Полнота">{{ $day['complete'] ? 'Полный день' : 'Частично / нет данных' }}</td>
                            <td data-label="Не занято">{{ $day['counts']['available'] }}</td>
                            <td data-label="Занято">{{ $day['counts']['booked'] }}</td>
                            <td data-label="Недоступно">{{ $day['counts']['unavailable'] }}</td>
                            <td data-label="Неизвестно">{{ $day['counts']['unknown'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @foreach ($grouped as $date => $slots)
            <details class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                <summary class="cursor-pointer text-sm font-semibold">{{ $date }} — {{ $slots->where('status', 'booked')->count() }} занятых интервалов</summary>
                <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
                    @foreach ($slots as $slot)
                        @php
                            $statusLabel = match ($slot->status) {
                                'booked' => 'Занято',
                                'available' => 'Не занято',
                                'unavailable' => 'Недоступно',
                                default => 'Неизвестно',
                            };
                        @endphp
                        <div class="rounded-md border border-gray-200 p-2 text-xs dark:border-gray-700">
                            <div class="font-medium">{{ $slot->starts_at->setTimezone($latest->timezone)->format('H:i') }}–{{ $slot->ends_at->setTimezone($latest->timezone)->format('H:i') }}</div>
                            <div>{{ $statusLabel }}</div>
                            <div>{{ $slot->price_minor !== null ? number_format($slot->price_minor / 100, 0, ',', ' ').' ₽ / '.$slot->price_unit_minutes.' мин' : 'Цена не определена' }}</div>
                        </div>
                    @endforeach
                </div>
            </details>
        @endforeach

        <p class="text-sm font-semibold">Последние сохранённые версии</p>
        <div class="mskba-bo-table mskba-bo-table--cards">
            <table class="mskba-bo-table__table">
                <thead><tr><th>Снимок</th><th>Получен</th><th>Покрытие</th><th>Актуальность</th><th>Контрольная сумма</th></tr></thead>
                <tbody>
                    @foreach ($history as $snapshot)
                        <tr>
                            <td data-label="Снимок">#{{ $snapshot->id }}</td>
                            <td data-label="Получен">{{ $snapshot->observed_at->setTimezone($snapshot->timezone)->format('d.m.Y H:i:s') }}</td>
                            <td data-label="Покрытие">{{ $snapshot->complete ? 'Полное' : 'Частичное' }}</td>
                            <td data-label="Актуальность">{{ $snapshot->isStale() ? 'Устарел' : 'В пределах TTL' }}</td>
                            <td data-label="Контрольная сумма">{{ substr($snapshot->checksum, 0, 12) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
