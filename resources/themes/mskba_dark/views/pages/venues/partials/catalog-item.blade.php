@php
    $mode = $mode ?? 'card';
    $showDescription = $mode === 'list';
    $venueUrl = $cardUrl ?? ($venue->matchedCourtUrl ?: route('venues.show', $venue->routeIdentifier()));
    $court = $venue->cardCourt($venueUrl);
    $paymentStates = $venue->paymentBadgeStates($court !== null ? $venueUrl : null);
    $approvalCourts = $court !== null ? ($court['requires_booking_approval'] ? [$court] : []) : ($venue->courtAccessSummary['approval'] ?? []);
    $courtName = $court['name'] ?? $cardCourtName ?? $venue->matchedCourtName;
    $description = $court !== null ? ($court['short_description'] ?? null) : $venue->shortDescription;
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
        <div class="catalog-card__badges venue-catalog-item__badges">
            <span class="catalog-card__badge">{{ $court['type_label'] ?? $venue->type }}</span>
            <span @class(['catalog-card__badge', 'is-closed' => ($court['operational_status'] ?? $venue->operationalStatusSlug) !== 'active'])>{{ $court['operational_status_label'] ?? $venue->operationalStatus }}</span>
        </div>

        <h2 class="catalog-card__title"><a href="{{ $venueUrl }}">{{ $venue->name }}</a></h2>

        @if($venue->displayAddress)
            <p class="venue-catalog-item__address"><i class="ti ti-map-pin" aria-hidden="true"></i><span>{{ $venue->displayAddress }}</span></p>
        @endif

        @if(isset($distanceMeters))
            <p class="venue-catalog-item__distance"><i class="ti ti-map-pin" aria-hidden="true"></i>{{ number_format($distanceMeters / 1000, 1, ',', ' ') }} км от этой площадки</p>
        @endif
        @if($courtName !== null)
            <p class="venue-catalog-item__match"><i class="ti ti-layout-grid" aria-hidden="true"></i>Подходящий зал: {{ $courtName }}</p>
        @endif

        @if($showDescription && $description)
            <p class="catalog-card__description venue-catalog-item__description">{{ $description }}</p>
        @endif

        <div class="venue-catalog-item__access">
            @include('venues.partials.catalog-payment-row', ['paymentStates' => $paymentStates, 'courtCount' => $courtCount, 'legacyPayment' => true])
            @if($approvalCourts !== [])
                <span><i class="ti ti-shield-check" aria-hidden="true"></i>{{ $court !== null ? 'Требуется подтверждение' : 'Есть залы с подтверждением' }}</span>
            @endif
            @if($court !== null)
                @if($court['requires_booking'])
                    <span><i class="ti ti-calendar" aria-hidden="true"></i>По предварительной записи</span>
                @endif
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
