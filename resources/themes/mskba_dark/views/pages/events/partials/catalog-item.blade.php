@php
    use App\Modules\Event\Domain\Enums\EventStatusEnum;
    use App\Modules\Event\Domain\Enums\EventTypeEnum;

    $mode = $mode ?? 'card';
    $timezone = $event->venue->schedule?->timezone ?: config('app.timezone');
    $startsAt = $event->starts_at->setTimezone($timezone);
    $endsAt = $event->ends_at->setTimezone($timezone);
    $photo = $event->venue->media->first();
    $isPast = $event->ends_at->isPast();
    $pastOutcome = match($event->status) {
        EventStatusEnum::COMPLETED => 'Состоялось',
        EventStatusEnum::CANCELLED => 'Отменено',
        default => 'Итог не указан',
    };
    $address = $event->venue->raw_address ?: $event->venue->location?->address?->full_address;
    $structuredShortAddress = implode(', ', array_filter([
        $event->venue->location?->address?->street,
        $event->venue->location?->address?->building,
    ]));
    $addressParts = array_values(array_filter(array_map('trim', explode(',', (string) $address))));
    $shortAddress = $structuredShortAddress ?: implode(', ', array_slice($addressParts, -2));
    $latitude = $event->venue->location?->address?->latitude;
    $longitude = $event->venue->location?->address?->longitude;
    $eventUrl = route('events.show', $event->routeIdentifier());
@endphp

<article @class([
    'event-category-item',
    'event-category-item--'.$mode,
    'is-past' => $isPast,
])>
    <a class="event-category-item__image" href="{{ $eventUrl }}" aria-label="{{ $event->title }}">
        <img src="{{ $photo?->publicUrl() ?: asset('images/venue-placeholder.png') }}" alt="">
    </a>

    <div class="event-category-item__body">
        <div class="event-category-item__badges">
            <span class="catalog-card__badge event-type-badge event-type-badge--{{ $event->type->value }}">{{ $event->type->label() }}</span>
            @if($isPast)
                <span class="catalog-card__badge event-type-badge is-muted">{{ $pastOutcome }}</span>
            @endif
        </div>

        <h2 class="event-category-item__title"><a href="{{ $eventUrl }}">{{ $event->title }}</a></h2>

        <div class="event-category-item__meta">
            @if($latitude !== null && $longitude !== null)
                <button
                    class="event-category-item__location js-handler"
                    type="button"
                    data-handler="modal"
                    data-modal-action="open"
                    data-modal-target="events-catalog-map"
                    data-catalog-map-open
                    data-latitude="{{ $latitude }}"
                    data-longitude="{{ $longitude }}"
                    data-title="{{ $event->venue->name }}"
                    data-address="{{ $address }}"
                >
                    <i class="ti ti-map-pin" aria-hidden="true"></i>
                    <span>{{ $event->venue->name }}@if($shortAddress), {{ $shortAddress }}@endif</span>
                </button>
            @else
                <p><i class="ti ti-map-pin" aria-hidden="true"></i><span>{{ $event->venue->name }}@if($shortAddress), {{ $shortAddress }}@endif</span></p>
            @endif
            <p><i class="ti ti-clock" aria-hidden="true"></i><span>{{ $startsAt->format('d.m.Y · H:i') }}–{{ $endsAt->format('H:i') }}</span></p>
            <p><i class="ti ti-users" aria-hidden="true"></i><span>{{ $event->participants_count }}{{ $event->max_participants ? ' / '.$event->max_participants : ' / ∞' }} участников</span></p>
        </div>

        @if($event->type !== EventTypeEnum::GAME && $event->games->isNotEmpty())
            <div class="event-category-item__mini-games">
                <strong><i class="ti ti-device-gamepad-2" aria-hidden="true"></i>Мини-игры: {{ $event->games->count() }}</strong>
                @if($mode === 'card')
                    @foreach($event->games->take(2) as $game)
                        <span>{{ $game->title ?: 'Игра #'.$game->id }}</span>
                    @endforeach
                    @if($event->games->count() > 2)<small>И еще {{ $event->games->count() - 2 }}…</small>@endif
                @endif
            </div>
        @endif
    </div>

    <a class="btn btn--secondary btn--sm event-category-item__details" href="{{ $eventUrl }}">
        <span>Подробнее</span><i class="ti ti-arrow-right" aria-hidden="true"></i>
    </a>
</article>
