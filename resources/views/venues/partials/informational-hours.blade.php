@php
    $rows = \App\Modules\Venue\Application\Services\CompactWeeklyHours::rows($scheduleGroup['weeklyHours'] ?? []);
@endphp
<div class="venue-informational-hours" role="group" aria-label="Часы работы">
    @if($legacyHours ?? false)
        <i class="ti ti-clock venue-informational-hours__icon" aria-hidden="true"></i>
    @else
        <svg class="venue-informational-hours__icon" aria-hidden="true" width="16" height="16"><use href="#clock"/></svg>
    @endif
    <div class="venue-informational-hours__rows">
        @foreach($rows as $row)
            <p>@if($row['label'] !== '')<strong>{{ $row['label'] }}</strong> — @endif{{ $row['hours'] }}</p>
        @endforeach
        @if(!empty($scheduleGroup['exceptions']))
            <h4>Изменения графика</h4>
            @foreach($scheduleGroup['exceptions'] as $exception)
                <p><strong>{{ $exception['label'] }} ({{ $exception['weekday'] }})</strong> — {{ \App\Modules\Venue\Application\Services\CompactWeeklyHours::intervalLabel($exception['intervals'] ?? null) }}</p>
            @endforeach
        @endif
    </div>
</div>
