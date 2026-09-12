@php
    $title = 'Мероприятия';
    $currentView = in_array(request('view'), ['list', 'map'], true) ? request('view') : 'cards';
    $activeFilterCount = collect([
        $period === 'past',
        filled($typeFilter),
        filled($dateFrom),
        filled($dateTo),
        filled($outcome),
        $statusFilter !== 'not_cancelled',
        filled($venueId),
        $hasMiniGames,
    ])->filter()->count();
    $createQuery = array_filter(['type' => $selectedType?->value]);
    $createUrl = route('events.create', $createQuery);
    $createRedirectUrl = route('events.create', $createQuery, false);
    $mapEvents = collect($events->items())
        ->filter(fn ($event) => $event->venue->location?->address?->latitude !== null && $event->venue->location?->address?->longitude !== null)
        ->map(function ($event) {
            $timezone = $event->venue->schedule?->timezone ?: config('app.timezone');
            $startsAt = $event->starts_at->setTimezone($timezone);
            $address = $event->venue->raw_address ?: $event->venue->location?->address?->full_address;

            return [
                'latitude' => (float) $event->venue->location->address->latitude,
                'longitude' => (float) $event->venue->location->address->longitude,
                'venue_name' => $event->venue->name,
                'address' => $address,
                'event' => [
                    'title' => $event->title,
                    'type' => $event->type->label(),
                    'starts_at' => $startsAt->format('d.m.Y · H:i'),
                    'url' => route('events.show', $event->routeIdentifier()),
                ],
            ];
        })->values();
@endphp

@extends('theme::layouts.default-category', [
    'title' => $title,
    'categoryId' => 'events',
    'categoryClass' => 'events-category-catalog',
    'currentView' => $currentView,
    'hasMap' => true,
    'mobileFilterModalId' => 'event-catalog-filters',
])

@section('category-navigation')
    @include('theme::pages.events.partials.catalog-navigation')
@endsection

@section('category-filters-desktop')
    @include('theme::pages.events.partials.catalog-filters', [
        'formId' => 'event-catalog-filter-form-desktop',
        'scope' => 'desktop',
        'currentView' => $currentView,
    ])
@endsection

@section('category-search')
    <form id="event-catalog-filter-form" method="GET" action="{{ route('events.index') }}">
        <label class="catalog-toolbar__search" aria-label="Поиск мероприятий">
            <i class="ti ti-search" aria-hidden="true"></i>
            <input type="search" name="q" value="{{ $search }}" placeholder="Название, описание или площадка" form="event-catalog-filter-form" data-default-category-search>
        </label>
        @if(filled($typeFilter))<input type="hidden" name="type" value="{{ $typeFilter }}">@endif
        @if($period === 'past')<input type="hidden" name="period" value="past">@endif
        @if(filled($dateFrom))<input type="hidden" name="date_from" value="{{ $dateFrom }}">@endif
        @if(filled($dateTo))<input type="hidden" name="date_to" value="{{ $dateTo }}">@endif
        @if(filled($outcome))<input type="hidden" name="outcome" value="{{ $outcome }}">@endif
        @if($statusFilter !== 'not_cancelled')<input type="hidden" name="status" value="{{ $statusFilter }}">@endif
        @if(filled($venueId))<input type="hidden" name="venue_id" value="{{ $venueId }}">@endif
        @if($hasMiniGames)<input type="hidden" name="has_mini_games" value="1">@endif
        <input type="hidden" name="view" value="{{ $currentView === 'cards' ? '' : $currentView }}" data-default-category-view-input @disabled($currentView === 'cards')>
    </form>
    <span class="catalog-toolbar events-catalog-filters__toolbar default-category__compat-label" aria-hidden="true"></span>
@endsection

@section('category-active-filter-count')
    @if($activeFilterCount > 0){{ $activeFilterCount }}@endif
@endsection

@section('category-toolbar-actions')
    @auth
        <a class="btn btn--primary default-category-toolbar__action-button" href="{{ $createUrl }}" aria-label="Создать мероприятие" title="Создать мероприятие" data-tooltip-variant="title">
            <i class="ti ti-plus" aria-hidden="true"></i>
        </a>
    @else
        <button type="button" class="btn btn--primary default-category-toolbar__action-button js-handler" aria-label="Создать мероприятие" title="Создать мероприятие" data-tooltip-variant="title" data-handler="modal" data-modal-action="open" data-modal-target="auth-entry-classic" data-auth-redirect-url="{{ $createRedirectUrl }}">
            <i class="ti ti-plus" aria-hidden="true"></i>
        </button>
    @endauth
@endsection

@section('category-results-cards')
    <div class="default-category-results--cards event-category-results event-category-results--cards">
        @forelse($events as $event)
            @include('theme::pages.events.partials.catalog-item', ['event' => $event, 'mode' => 'card'])
        @empty
            <div class="default-category__empty event-category-catalog__empty"><i class="ti ti-ball-basketball" aria-hidden="true"></i><strong>Подходящих мероприятий пока нет</strong><span>Попробуйте изменить условия поиска</span><a class="btn btn--secondary btn--sm" href="{{ route('events.index') }}">Сбросить параметры</a></div>
        @endforelse
    </div>
@endsection

@section('category-results-list')
    <div class="default-category-results--list event-category-results event-category-results--list">
        @forelse($events as $event)
            @include('theme::pages.events.partials.catalog-item', ['event' => $event, 'mode' => 'list'])
        @empty
            <div class="default-category__empty event-category-catalog__empty"><i class="ti ti-ball-basketball" aria-hidden="true"></i><strong>Подходящих мероприятий пока нет</strong><span>Попробуйте изменить условия поиска</span><a class="btn btn--secondary btn--sm" href="{{ route('events.index') }}">Сбросить параметры</a></div>
        @endforelse
    </div>
@endsection

@section('category-results-map')
    <section class="event-category-map" data-event-category-map-frame>
        @if($mapEvents->isNotEmpty())
            <p class="event-category-map__status" data-event-category-map-status>Загружаем карту…</p>
            <div class="event-category-map__canvas" data-event-category-map data-yandex-map-api-key="{{ config('integrations.yandex.api_key') }}"></div>
            <script type="application/json" data-event-category-map-points>@json($mapEvents)</script>
        @else
            <div class="default-category__empty event-category-catalog__empty"><i class="ti ti-map-pin-off" aria-hidden="true"></i><strong>Нет мероприятий с доступными координатами</strong><span>Попробуйте изменить условия поиска</span><a class="btn btn--secondary btn--sm" href="{{ route('events.index') }}">Сбросить параметры</a></div>
        @endif
    </section>
@endsection

@section('category-pagination')
    @if($events->hasPages())
        <div class="event-category-catalog__pagination">{{ $events->links('theme::partials.pagination') }}</div>
    @endif
@endsection

@section('category-mobile-filters')
    @component('theme::partials.modal.layout', ['id' => 'event-catalog-filters', 'dialogClass' => 'default-category-mobile-filters__dialog'])
        <h2 class="modal_title" id="modal-title-event-catalog-filters">Фильтры мероприятий</h2>
        @include('theme::pages.events.partials.catalog-filters', ['formId' => 'event-catalog-filter-form-mobile', 'scope' => 'mobile', 'currentView' => $currentView])
    @endcomponent

    @component('theme::partials.modal.layout', ['id' => 'events-catalog-map', 'dialogClass' => 'venue-selector-map-modal__dialog event-venue-map-modal__dialog'])
        <h2 class="modal_title" id="modal-title-events-catalog-map" data-catalog-map-title>Площадка</h2>
        <p class="venue-selector-map__message" data-event-map-message>Загружаем карту…</p>
        <div class="venue-selector-map" data-event-map data-yandex-map-api-key="{{ config('integrations.yandex.api_key') }}" aria-label="Площадка на карте"></div>
    @endcomponent
@endsection
