@php
    $url = $cardUrl ?? ($venue->matchedCourtUrl ?: route('venues.show', $venue->routeIdentifier()));
    $court = $venue->cardCourt($url);
    $paymentStates = $venue->paymentBadgeStates($court !== null ? $url : null);
    $approval = $court !== null ? ($court['requires_booking_approval'] ? [$court] : []) : ($venue->courtAccessSummary['approval'] ?? []);
    $courtName = $court['name'] ?? $cardCourtName ?? $venue->matchedCourtName;
    $description = $court !== null ? ($court['short_description'] ?? null) : $venue->shortDescription;
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
        <div class="app-venues-catalog__badges">
            <span>{{ $court['type_label'] ?? $venue->type }}</span><span>{{ $court['operational_status_label'] ?? $venue->operationalStatus }}</span>
        </div>
        <h2><a href="{{ $url }}">{{ $venue->name }}</a></h2>
        @if($venue->displayAddress)
            <p class="app-venues-catalog__address">{{ $venue->displayAddress }}</p>
        @endif
        @if(isset($distanceMeters))
            <p class="app-venues-catalog__distance"><svg aria-hidden="true" width="16" height="16"><use href="#pin"/></svg> {{ number_format($distanceMeters / 1000, 1, ',', ' ') }} км от площадки</p>
        @endif
        @if($courtName !== null)
            <p class="app-venues-catalog__matched-court">Подходящий зал: {{ $courtName }}</p>
        @endif
        @if($description)
            <p class="app-venues-catalog__description">{{ $description }}</p>
        @endif
        <div class="app-venues-catalog__access">
            @include('venues.partials.catalog-payment-row', ['paymentStates' => $paymentStates, 'courtCount' => $courtCount])
            @if($approval !== [])<span>Требуется подтверждение</span>@endif
        </div>
        <a class="button secondary app-venues-catalog__details" href="{{ $url }}">Подробнее <span aria-hidden="true">→</span></a>
    </div>
</article>
