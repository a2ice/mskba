@php
    $mode = $mode ?? 'card';
    $url = route('tournaments.show', $tournament->routeIdentifier());
    $venue = $tournament->defaultVenue;
    $address = $venue?->raw_address ?: $venue?->location?->address?->full_address;
    $phase = $tournament->phase();
    $acceptsAdmissions = $tournament->acceptsAdmissions();
@endphp

<article @class(['tournament-category-item', 'tournament-category-item--'.$mode])>
    <a class="tournament-category-item__image" href="{{ $url }}" aria-label="{{ $tournament->title }}">
        <img src="{{ $tournament->cover?->publicUrl() ?: asset('images/venue-placeholder.png') }}" alt="">
    </a>

    <div class="tournament-category-item__body">
        <div class="tournament-category-item__badges">
            <span class="catalog-card__badge tournament-category-item__phase tournament-category-item__phase--{{ $phase->value }}">{{ $phase->label() }}</span>
            @if($acceptsAdmissions)
                <span class="catalog-card__badge tournament-category-item__recruitment">Набор открыт</span>
            @endif
        </div>

        <h2 class="tournament-category-item__title"><a href="{{ $url }}">{{ $tournament->title }}</a></h2>

        <div class="tournament-category-item__meta">
            <p><i class="ti ti-calendar-event" aria-hidden="true"></i><span>{{ $tournament->starts_on->format('d.m.Y') }}@if($tournament->ends_on) — {{ $tournament->ends_on->format('d.m.Y') }}@endif</span></p>
            @if($tournament->format)
                <p><i class="ti ti-ball-basketball" aria-hidden="true"></i><span>{{ $tournament->format->label() }}</span></p>
            @endif
            @if($tournament->recruitment_mode)
                <p><i class="ti ti-users-group" aria-hidden="true"></i><span>{{ $tournament->recruitment_mode->label() }}</span></p>
            @endif
            @if($tournament->enrollment_policy)
                <p><i class="ti ti-door-enter" aria-hidden="true"></i><span>{{ $tournament->enrollment_policy->label() }}</span></p>
            @endif
            @if($venue)
                <p><i class="ti ti-map-pin" aria-hidden="true"></i><span>{{ $venue->name }}@if($address), {{ $address }}@endif</span></p>
            @endif
        </div>

        @if($mode === 'card' && filled($tournament->short_description))
            <p class="tournament-category-item__description">{{ $tournament->short_description }}</p>
        @endif
    </div>

    <a class="btn btn--secondary btn--sm tournament-category-item__details" href="{{ $url }}">
        <span>Подробнее</span><i class="ti ti-arrow-right" aria-hidden="true"></i>
    </a>
</article>
