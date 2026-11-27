@if(isset($refreshState))
    @php
        $refreshStatus = $refreshState['state'];
        $refreshObserved = ($refreshState['observed_at'] ?? null)
            ? \Carbon\CarbonImmutable::parse($refreshState['observed_at'])->setTimezone('Europe/Moscow')->format('d.m H:i')
            : null;
        $refreshLabel = match ($refreshStatus) {
            'updating' => 'Обновляем…',
            'cached' => 'Расписание актуально',
            'failed' => 'Не удалось обновить',
            default => 'Требуется обновление',
        };
        $refreshHint = match ($refreshStatus) {
            'updating' => 'Обновляем расписание. Пока показаны сохранённые данные.',
            'failed' => 'Не удалось обновить расписание. Показаны последние сохранённые данные.',
            default => $refreshLabel,
        };
    @endphp
    <span class="venue-schedule-refresh-status" role="status" data-venue-refresh-message
        data-refresh-state="{{ $refreshStatus }}"
        title="{{ $refreshHint }}{{ $refreshObserved ? ' · Последнее обновление: '.$refreshObserved : '' }}"
        data-refresh-observed="{{ $refreshObserved }}">
        <span class="image-upload-loading__spinner" data-venue-refresh-spinner aria-hidden="true" @if($refreshStatus !== 'updating') hidden @endif></span>
        <svg data-venue-refresh-error aria-hidden="true" viewBox="0 0 16 16" fill="none" @if($refreshStatus !== 'failed') hidden @endif>
            <path d="M4 4l8 8m0-8-8 8" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
        </svg>
        <span data-venue-refresh-label>{{ $refreshLabel }}</span>
    </span>
@endif
