@php
    $conditions = $conditions ?? [];
    $zoneCard = $zoneCard ?? false;
    $operationalStates = $operationalStates ?? ['active'];
    $groups = [];
    $payments = [];
    foreach (['free', 'paid', 'unknown'] as $state) {
        if (!in_array($state, $paymentStates, true)) continue;
        $payments[] = match($state) {
            'free' => ['icon' => 'free', 'label' => 'Бесплатно', 'tone' => 'positive'],
            'paid' => ['icon' => 'paid', 'label' => 'Платно', 'tone' => 'warning'],
            default => ['icon' => 'unknown', 'label' => 'Условия уточняются', 'tone' => 'neutral'],
        };
    }
    if ($payments === []) {
        $payments[] = ['icon' => 'unknown', 'label' => 'Нет доступных залов', 'tone' => 'neutral'];
    }
    $groups[] = ['segments' => $payments, 'kind' => 'payment'];

    $statuses = [];
    foreach (['active', 'temporarily_closed'] as $state) {
        if (!in_array($state, $operationalStates, true)) continue;
        $statuses[] = $state === 'active'
            ? ['icon' => 'active', 'label' => 'Работает', 'tone' => 'positive']
            : ['icon' => 'closed', 'label' => 'Временно закрыта', 'tone' => 'negative'];
    }
    if ($statuses !== []) $groups[] = ['segments' => $statuses, 'kind' => 'status'];
    if ($courtCount > 1) {
        $groups[] = ['segments' => [['icon' => 'zones', 'value' => $courtCount, 'label' => 'Игровых зон: '.$courtCount]], 'kind' => 'plain'];
    }
    if ($conditions['booking'] ?? false) {
        $groups[] = ['segments' => [['icon' => 'calendar', 'label' => $zoneCard ? 'По предварительной записи' : 'Есть зоны с предварительной записью']], 'kind' => 'plain'];
    }
    if ($conditions['approval'] ?? false) {
        $groups[] = ['segments' => [['icon' => 'approval', 'label' => $zoneCard ? 'Требуется подтверждение' : 'Есть зоны, требующие подтверждения']], 'kind' => 'plain'];
    }
@endphp
<div class="venue-catalog-card-icons">
    @foreach($groups as $group)
        @unless($loop->first)<span class="venue-catalog-card-icons__separator" aria-hidden="true">|</span>@endunless
        <div @class(['venue-catalog-card-icons__group', 'venue-catalog-card-icons__group--pill' => $group['kind'] !== 'plain']) data-venue-icon-group="{{ $group['kind'] }}">
            @foreach($group['segments'] as $item)
                <button type="button" @class(['venue-catalog-card-icons__item', 'venue-catalog-card-icons__item--'.($item['tone'] ?? 'plain')]) data-venue-card-tooltip="{{ $item['label'] }}" aria-label="{{ $item['label'] }}" aria-expanded="false">
                    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                        @switch($item['icon'])
                            @case('free')
                                <circle cx="12" cy="12" r="8"/><path d="M9 9h4a2 2 0 0 1 0 4H9m2-4v6m-3-1h5M4 4l16 16"/>
                                @break
                            @case('paid')
                                <circle cx="12" cy="12" r="9"/><path d="M9 7h4a3 3 0 0 1 0 6H9m2-6v10m-3-2h6"/>
                                @break
                            @case('unknown')
                                <circle cx="12" cy="12" r="9"/><path d="M8 12h8"/>
                                @break
                            @case('active')
                                <circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>
                                @break
                            @case('closed')
                                <rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3m-4 4v3"/>
                                @break
                            @case('zones')
                                <rect x="3" y="4" width="18" height="16" rx="2"/><path d="M12 4v16"/><circle cx="12" cy="12" r="3"/><path d="M3 9h3v6H3m18-6h-3v6h3"/>
                                @break
                            @case('calendar')
                                <rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18m-9 3v3l2 1"/>
                                @break
                            @default
                                <path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11z"/><path d="m9 12 2 2 4-4"/>
                        @endswitch
                    </svg>
                    @if(isset($item['value']))<span aria-hidden="true">{{ $item['value'] }}</span>@endif
                </button>
            @endforeach
        </div>
    @endforeach
</div>
