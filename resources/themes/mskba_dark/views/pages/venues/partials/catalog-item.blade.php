@php
    $mode = $mode ?? 'card';
    $showDescription = $mode === 'list';
    $venueUrl = route('venues.show', $venue->routeIdentifier());
@endphp

<article class="catalog-card venue-catalog-item venue-catalog-item--{{ $mode }}">
    <a class="catalog-card__image venue-catalog-item__image" href="{{ $venueUrl }}">
        <img src="{{ $venue->imageUrl ?: asset('images/venue-placeholder.png') }}" alt="Фото площадки {{ $venue->name }}">
    </a>

    <div class="catalog-card__body venue-catalog-item__body">
        <div class="catalog-card__badges venue-catalog-item__badges">
            <span class="catalog-card__badge">{{ $venue->type }}</span>
            <span @class(['catalog-card__badge', 'is-closed' => $venue->operationalStatusSlug !== 'active'])>{{ $venue->operationalStatus }}</span>
        </div>

        <h2 class="catalog-card__title"><a href="{{ $venueUrl }}">{{ $venue->name }}</a></h2>

        @if($venue->displayAddress)
            <p class="venue-catalog-item__address"><i class="ti ti-map-pin" aria-hidden="true"></i><span>{{ $venue->displayAddress }}</span></p>
        @endif

        @if($showDescription && $venue->shortDescription)
            <p class="catalog-card__description venue-catalog-item__description">{{ $venue->shortDescription }}</p>
        @endif

        <div class="venue-catalog-item__access">
            <span>
                <i class="ti {{ $venue->requiresPayment === true ? 'ti-currency-ruble' : ($venue->requiresPayment === false ? 'ti-gift' : 'ti-help-circle') }}" aria-hidden="true"></i>
                {{ $venue->requiresPayment === true ? 'Платно' : ($venue->requiresPayment === false ? 'Бесплатно' : 'Условия уточняются') }}
            </span>
            @if($venue->requiresBookingApproval)
                <span><i class="ti ti-shield-check" aria-hidden="true"></i>По подтверждению</span>
            @endif
        </div>
    </div>

    <div class="catalog-card__actions venue-catalog-item__actions">
        <a class="btn btn--secondary btn--sm" href="{{ $venueUrl }}">Подробнее<i class="ti ti-arrow-right" aria-hidden="true"></i></a>
        @if($venue->canEdit)
            <a class="venue-catalog-item__edit" href="{{ route('account.venues.edit', $venue->routeIdentifier()) }}"><i class="ti ti-pencil" aria-hidden="true"></i>Редактировать</a>
        @endif
    </div>
</article>
