@php
    $conditions = $conditions ?? [];
    $zoneCard = $zoneCard ?? false;
    $items = [];
    if ($courtCount > 1) {
        $items[] = ['icon' => 'zones', 'value' => $courtCount, 'label' => 'Игровых зон: '.$courtCount];
    }
    if ($conditions['booking'] ?? false) {
        $items[] = ['icon' => 'calendar', 'value' => null, 'label' => $zoneCard ? 'По предварительной записи' : 'Есть зоны с предварительной записью'];
    }
    if ($conditions['approval'] ?? false) {
        $items[] = ['icon' => 'approval', 'value' => null, 'label' => $zoneCard ? 'Требуется подтверждение' : 'Есть зоны, требующие подтверждения'];
    }
@endphp
<div class="venue-catalog-payment-row">
    <div class="venue-catalog-payment-row__badges">
        @include('venues.partials.payment-badge', ['paymentStates' => $paymentStates, 'legacyPayment' => $legacyPayment ?? false])
    </div>
    @if($items !== [])
        <div class="venue-catalog-card-icons">
            @foreach($items as $item)
                @unless($loop->first)<span class="venue-catalog-card-icons__separator" aria-hidden="true">|</span>@endunless
                <button type="button" class="venue-catalog-card-icons__item" data-venue-card-tooltip="{{ $item['label'] }}" aria-label="{{ $item['label'] }}" aria-expanded="false">
                    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                        @if($item['icon'] === 'zones')
                            <rect x="3" y="4" width="18" height="16" rx="2"/><path d="M12 4v16"/><circle cx="12" cy="12" r="3"/><path d="M3 9h3v6H3m18-6h-3v6h3"/>
                        @elseif($item['icon'] === 'calendar')
                            <rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18m-9 3v3l2 1"/>
                        @else
                            <path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11z"/><path d="m9 12 2 2 4-4"/>
                        @endif
                    </svg>
                    @if($item['value'] !== null)<span aria-hidden="true">{{ $item['value'] }}</span>@endif
                </button>
            @endforeach
        </div>
    @endif
</div>
