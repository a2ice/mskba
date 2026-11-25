@php
    $showCatalogDistance = isset($catalogDistanceMeters, $catalogDistanceOriginName);
    $catalogDistanceTooltip = $showCatalogDistance ? 'Расстояние до площадки '.$catalogDistanceOriginName : null;
@endphp
@if($showCatalogDistance)
    <div class="venue-catalog-address-row">
@endif
@if($venue->displayAddress)
    <button type="button" class="venue-catalog-address" data-venue-map-open aria-label="Показать {{ $venue->displayAddress }} на Яндекс Картах">
        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 5-8 10-8 10S4 15 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/></svg>
        <span>{{ $venue->displayAddress }}</span>
    </button>
@endif
@if($showCatalogDistance)
        <button type="button" class="venue-catalog-address-distance"
            data-venue-card-tooltip="{{ $catalogDistanceTooltip }}" aria-expanded="false"
            aria-label="{{ number_format($catalogDistanceMeters / 1000, 1, ',', ' ') }} км. {{ $catalogDistanceTooltip }}"
            title="{{ $catalogDistanceTooltip }}">({{ number_format($catalogDistanceMeters / 1000, 1, ',', ' ') }} км)</button>
    </div>
@endif
@if(count($venue->metroStations))
    <div class="venue-catalog-metro" aria-label="Ближайшие станции метро">
        @foreach($venue->metroStations as $station)
            <span class="venue-catalog-metro__station"><span class="venue-catalog-metro__dot" style="--metro-color: {{ preg_match('/\A#?[0-9A-Fa-f]{6}\z/D', (string) $station->lineColor) ? '#'.ltrim((string) $station->lineColor, '#') : '#758392' }}" aria-hidden="true"></span>{{ $station->name }}</span>
        @endforeach
    </div>
@endif
