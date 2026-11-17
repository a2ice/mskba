@php
    $mode = $mode ?? 'card';
    $showDescription = $mode === 'list';
    $venueUrl = $venue->matchedCourtUrl ?: route('venues.show', $venue->routeIdentifier());
    $freeCourts = $venue->courtAccessSummary['free'] ?? [];
    $paidCourts = $venue->courtAccessSummary['paid'] ?? [];
    $approvalCourts = $venue->courtAccessSummary['approval'] ?? [];
    $unknownCourts = $venue->courtAccessSummary['unknown'] ?? [];
@endphp

<article class="catalog-card venue-catalog-item venue-catalog-item--{{ $mode }}" data-venue-id="{{ $venue->id }}">
    <button type="button" class="mskba-favorite-heart mskba-favorite-heart--card"
        data-favorite-venue="{{ $venue->id }}"
        aria-label="Добавить в избранное" title="Добавить в избранное"
        aria-pressed="false"><svg aria-hidden="true"><use href="#heart"/></svg></button>
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
            @if($freeCourts !== [])
                <span><i class="ti ti-gift" aria-hidden="true"></i>{{ $paidCourts !== [] ? 'Есть бесплатный доступ' : 'Бесплатно' }}</span>
            @endif
            @if($paidCourts !== [])
                <span><i class="ti ti-currency-ruble" aria-hidden="true"></i>{{ $freeCourts !== [] ? 'Есть платные залы' : 'Платно' }}</span>
            @endif
            @if($approvalCourts !== [])
                <span><i class="ti ti-shield-check" aria-hidden="true"></i>Есть залы с подтверждением</span>
            @endif
            @if($freeCourts === [] && $paidCourts === [])
                <span><i class="ti ti-help-circle" aria-hidden="true"></i>{{ $unknownCourts !== [] ? 'Условия уточняются' : 'Нет доступных залов' }}</span>
            @endif
            @if($venue->matchedCourtName !== null)
                <span><i class="ti ti-layout-grid" aria-hidden="true"></i>Подходящий зал: {{ $venue->matchedCourtName }}</span>
                @if($venue->matchedRequiresBooking)
                    <span><i class="ti ti-calendar" aria-hidden="true"></i>По предварительной записи</span>
                @endif
                @if($venue->matchedRequiresApproval)
                    <span><i class="ti ti-shield-check" aria-hidden="true"></i>Требуется подтверждение</span>
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
