@php
    $mode = $mode ?? 'card';
    $venueUrl = $cardUrl ?? ($venue->matchedCourtUrl ?: route('venues.show', $venue->routeIdentifier()));
    $court = $venue->cardCourt($venueUrl);
    $paymentStates = $venue->paymentBadgeStates($court !== null ? $venueUrl : null);
    $conditions = $venue->cardConditions($court);
    $courtName = $court['name'] ?? $cardCourtName ?? $venue->matchedCourtName;
    $description = \App\Modules\Venue\Application\DTO\VenueListItemDTO::catalogDescription($court !== null ? ($court['short_description'] ?? null) : $venue->shortDescription);
    $courtCount = $court !== null || !empty($cardCourtName) ? 0 : $venue->courtCount();
@endphp

<article class="catalog-card venue-catalog-item venue-catalog-item--{{ $mode }}" data-venue-id="{{ $venue->id }}">
    <button type="button" class="mskba-favorite-heart mskba-favorite-heart--card"
        data-favorite-venue="{{ $venue->id }}"
        aria-label="Добавить в избранное" title="Добавить в избранное"
        aria-pressed="false"><svg aria-hidden="true"><use href="#heart"/></svg></button>
    <a class="catalog-card__image venue-catalog-item__image" href="{{ $venueUrl }}">
        <img src="{{ ($court['image_url'] ?? $venue->imageUrl) ?: asset('images/venue-placeholder.png') }}" alt="Фото площадки {{ $venue->name }}">
    </a>

    <div class="catalog-card__body venue-catalog-item__body">
        <div class="venue-catalog-card-top">
            <div class="catalog-card__badges venue-catalog-item__badges"><span class="catalog-card__badge">{{ $court['type_label'] ?? $venue->type }}</span></div>
            @include('venues.partials.catalog-payment-row', ['paymentStates' => $paymentStates, 'operationalStates' => $venue->cardOperationalStates($court), 'courtCount' => $courtCount, 'conditions' => $conditions, 'zoneCard' => $court !== null])
        </div>

        <h2 class="catalog-card__title"><a href="{{ $venueUrl }}">{{ $venue->name }}</a></h2>

        @include('venues.partials.catalog-address', ['venue' => $venue])

        @if(isset($distanceMeters))
            <p class="venue-catalog-item__distance"><i class="ti ti-map-pin" aria-hidden="true"></i>{{ number_format($distanceMeters / 1000, 1, ',', ' ') }} км от этой площадки</p>
        @endif
        @if($courtName !== null)
            <p class="venue-catalog-item__match"><i class="ti ti-layout-grid" aria-hidden="true"></i>Подходящий зал: {{ $courtName }}</p>
        @endif

        @if($description !== '')
            <p class="venue-catalog-card-description">{{ $description }}</p>
        @endif
        @if($venue->canEdit)
            <a class="venue-catalog-item__edit" href="{{ route('account.venues.edit', $venue->routeIdentifier()) }}"><i class="ti ti-pencil" aria-hidden="true"></i>Редактировать</a>
        @endif
    </div>
</article>
