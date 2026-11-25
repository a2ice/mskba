@php
    $url = $cardUrl ?? ($venue->matchedCourtUrl ?: route('venues.show', $venue->routeIdentifier()));
    $paymentStates = $venue->paymentBadgeStates(!empty($cardCourtName) ? ($cardUrl ?? null) : null);
    $approval = $venue->courtAccessSummary['approval'] ?? [];
@endphp
<article class="app-venues-catalog__card panel app-venue-card" data-venue-id="{{ $venue->id }}">
    <a class="app-venues-catalog__photo" href="{{ $url }}">
        <img src="{{ $venue->imageUrl ?: asset('images/venue-placeholder.png') }}" alt="Площадка {{ $venue->name }}" loading="lazy">
    </a>
    <button class="mskba-favorite-heart mskba-favorite-heart--card" type="button"
        data-favorite-venue="{{ $venue->id }}" aria-label="Добавить в избранное"
        title="Добавить в избранное" aria-pressed="false">
        <svg aria-hidden="true"><use href="#heart"/></svg>
    </button>
    <div class="app-venues-catalog__card-body">
        <div class="app-venues-catalog__badges">
            <span>{{ $venue->type }}</span><span>{{ $venue->operationalStatus }}</span>
        </div>
        <h2><a href="{{ $url }}">{{ $venue->name }}</a></h2>
        @if($venue->displayAddress)
            <p class="app-venues-catalog__address">{{ $venue->displayAddress }}</p>
        @endif
        @if(isset($distanceMeters))
            <p class="app-venues-catalog__distance"><svg aria-hidden="true" width="16" height="16"><use href="#pin"/></svg> {{ number_format($distanceMeters / 1000, 1, ',', ' ') }} км от площадки</p>
        @endif
        @if(!empty($cardCourtName))
            <p class="app-venues-catalog__matched-court">{{ $cardCourtName }}</p>
        @endif
        @if($venue->shortDescription)
            <p class="app-venues-catalog__description">{{ $venue->shortDescription }}</p>
        @endif
        <div class="app-venues-catalog__access">
            @include('venues.partials.payment-badge', ['paymentStates' => $paymentStates])
            @if($approval !== [])<span>Требуется подтверждение</span>@endif
        </div>
        <a class="button secondary app-venues-catalog__details" href="{{ $url }}">Подробнее <span aria-hidden="true">→</span></a>
    </div>
</article>
