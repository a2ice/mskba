@php
    $url = $cardUrl ?? ($venue->matchedCourtUrl ?: route('venues.show', $venue->routeIdentifier()));
    $court = $venue->cardCourt($url);
    $paymentStates = $venue->paymentBadgeStates($court !== null ? $url : null);
    $conditions = $venue->cardConditions($court);
    $courtName = $court['name'] ?? $cardCourtName ?? $venue->matchedCourtName;
    $description = \App\Modules\Venue\Application\DTO\VenueListItemDTO::catalogDescription($court !== null ? ($court['short_description'] ?? null) : $venue->shortDescription);
    $courtCount = $court !== null || !empty($cardCourtName) ? 0 : $venue->courtCount();
@endphp
<article class="app-venues-catalog__card panel app-venue-card" data-venue-id="{{ $venue->id }}">
    <a class="app-venues-catalog__photo" href="{{ $url }}">
        <img src="{{ ($court['image_url'] ?? $venue->imageUrl) ?: asset('images/venue-placeholder.png') }}" alt="Площадка {{ $venue->name }}" loading="lazy">
    </a>
    <button class="mskba-favorite-heart mskba-favorite-heart--card" type="button"
        data-favorite-venue="{{ $venue->id }}" aria-label="Добавить в избранное"
        title="Добавить в избранное" aria-pressed="false">
        <svg aria-hidden="true"><use href="#heart"/></svg>
    </button>
    <div class="app-venues-catalog__card-body">
        <div class="venue-catalog-card-top">
            <div class="app-venues-catalog__badges"><span>{{ $court['type_label'] ?? $venue->type }}</span></div>
            @include('venues.partials.catalog-payment-row', ['paymentStates' => $paymentStates, 'operationalStates' => $venue->cardOperationalStates($court), 'courtCount' => $courtCount, 'conditions' => $conditions, 'zoneCard' => $court !== null])
        </div>
        <h2><a href="{{ $url }}">{{ $venue->name }}</a></h2>
        @include('venues.partials.catalog-address', [
            'venue' => $venue,
            'catalogDistanceMeters' => $distanceMeters ?? null,
            'catalogDistanceOriginName' => $distanceOriginName ?? null,
        ])
        @if($courtName !== null)
            <p class="app-venues-catalog__matched-court">Подходящий зал: {{ $courtName }}</p>
        @endif
        @if($description !== '')
            <p class="venue-catalog-card-description">{{ $description }}</p>
        @endif
    </div>
</article>
