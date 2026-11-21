@php
    // One Blade component for the classic venue page and PageBuilder catalog.
    $mapVenue = $mapVenue ?? null;
    $mapPreview = $mapPreview ?? null;
    $mapShowCurrentVenueLink = $mapShowCurrentVenueLink ?? false;
    $mapName = $mapPreview['name'] ?? $mapVenue?->name ?? 'Площадка';
    $mapAddress = $mapPreview['address'] ?? ($mapVenue?->address?->display ?: $mapVenue?->rawAddress);
    $mapVenueUrl = $mapPreview['url'] ?? ($mapVenue ? route('venues.show', $mapVenue->routeIdentifier()) : '');
@endphp
<dialog class="app-venue-overlay app-venue-map-dialog" data-venue-map-dialog data-current-venue-link="{{ $mapShowCurrentVenueLink ? 'true' : 'false' }}" @if($mapPreview) data-map-preview @endif aria-label="Положение площадки на карте">
        <div class="app-venue-overlay__header"><h2><span data-venue-map-title>{{ $mapName }} — на карте</span></h2><button type="button" data-venue-overlay-close aria-label="Закрыть"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M5 5L19 19M19 5L5 19"/></svg></button></div>
        <div class="app-venue-map-frame" data-venue-map-frame
            data-lat="{{ $mapVenue?->address?->latitude }}" data-lon="{{ $mapVenue?->address?->longitude }}"
            data-name="{{ $mapName }}" data-venue-url="{{ $mapVenueUrl }}" data-map-api-key="{{ $mapPreview ? '' : ($mapVenue?->about?->mapApiKey ?? config('integrations.yandex.api_key')) }}"
            data-metro-stations='@json($mapVenue?->metroStations ?? [])'>
            @if($mapPreview) @include('ui-studio.cases.venue-map-fixture-canvas') @endif
        </div>
        <div class="app-venue-map-options">
            <p class="app-venue-map-options__address">{{ $mapAddress }}</p>
            <label class="app-venue-map-options__toggle"><input type="checkbox" data-venue-nearby-toggle> Показать площадки рядом</label>
            <div class="app-venue-map-options__range" data-venue-nearby-options hidden>
                <label for="venue-nearby-radius">Радиус: <output data-venue-nearby-distance>3</output> км</label>
                <input id="venue-nearby-radius" type="range" min="1" max="10" step="1" value="3" data-venue-nearby-radius>
                <button type="button" class="button primary" data-venue-nearby-apply
                    data-nearby-url="{{ $mapVenue ? route('venues.nearby-map', $mapVenue->routeIdentifier()) : '' }}">Применить</button>
            </div>
            <p class="caption" data-venue-nearby-status role="status" hidden></p>
        </div>
    </dialog>
