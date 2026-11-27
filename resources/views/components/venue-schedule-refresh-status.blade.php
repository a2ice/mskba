@if(isset($refreshState))
    <p class="muted" role="status" data-venue-refresh-message>
        @if($refreshState['state'] === 'updating')
            Обновляем расписание…
        @elseif($refreshState['state'] === 'cached')
            Расписание актуально
        @elseif($refreshState['state'] === 'failed')
            Не удалось обновить расписание. Показаны последние сохранённые данные.
        @else
            Требуется обновление расписания
        @endif
        @if($refreshState['observed_at'] ?? null)
            <span> · {{ \Carbon\CarbonImmutable::parse($refreshState['observed_at'])->setTimezone('Europe/Moscow')->format('d.m H:i') }}</span>
        @endif
    </p>
@endif
