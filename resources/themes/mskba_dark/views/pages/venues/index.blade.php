@php
    $title = 'Площадки';
    $currentView = in_array(($filters['view'] ?? null), ['list', 'map'], true) ? $filters['view'] : 'cards';
    $activeFilterCount = collect(['type', 'operational_status', 'access'])
        ->filter(fn ($key) => filled($filters[$key] ?? null))
        ->count();
    $mapVenues = collect($venues)
        ->filter(fn ($venue) => $venue->latitude !== null && $venue->longitude !== null)
        ->map(fn ($venue) => [
            'latitude' => $venue->latitude,
            'longitude' => $venue->longitude,
            'name' => $venue->name,
            'address' => $venue->displayAddress,
            'url' => route('venues.show', $venue->routeIdentifier()),
        ])->values();
@endphp

@extends('theme::layouts.default-category', [
    'title' => $title,
    'categoryId' => 'venues',
    'categoryClass' => 'venues-catalog',
    'currentView' => $currentView,
    'hasMap' => true,
    'mobileFilterModalId' => 'venue-catalog-filters',
])

@section('category-navigation')
    @include('theme::pages.venues.partials.catalog-navigation')
@endsection

@section('category-filters-desktop')
    @include('theme::pages.venues.partials.catalog-filters', [
        'formId' => 'venue-catalog-filter-form-desktop',
        'scope' => 'desktop',
        'currentView' => $currentView,
    ])
@endsection

@section('category-search')
    <form id="venue-catalog-filter-form" method="GET" action="{{ route('venues') }}">
        <label class="catalog-toolbar__search" aria-label="Поиск площадок">
            <i class="ti ti-search" aria-hidden="true"></i>
            <input type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Название, адрес или описание" form="venue-catalog-filter-form" data-default-category-search>
        </label>
        @foreach(['type', 'operational_status', 'access'] as $filterKey)
            @if(filled($filters[$filterKey] ?? null))
                <input type="hidden" name="{{ $filterKey }}" value="{{ $filters[$filterKey] }}">
            @endif
        @endforeach
        <input type="hidden" name="view" value="{{ $currentView === 'cards' ? '' : $currentView }}" data-default-category-view-input @disabled($currentView === 'cards')>
    </form>
@endsection

@section('category-active-filter-count')
    @if($activeFilterCount > 0){{ $activeFilterCount }}@endif
@endsection

@section('category-toolbar-actions')
    @auth
        <a class="btn btn--primary default-category-toolbar__action-button" href="{{ route('venues.create') }}" aria-label="Добавить площадку" title="Добавить площадку" data-tooltip-variant="title">
            <i class="ti ti-plus" aria-hidden="true"></i>
        </a>
    @else
        <button type="button" class="btn btn--primary default-category-toolbar__action-button js-handler" aria-label="Добавить площадку" title="Добавить площадку" data-tooltip-variant="title" data-handler="modal" data-modal-action="open" data-modal-target="auth-entry-classic" data-auth-redirect-url="{{ route('venues.create', [], false) }}">
            <i class="ti ti-plus" aria-hidden="true"></i>
        </button>
    @endauth
@endsection

@section('category-results-cards')
    <div class="default-category-results--cards venues-catalog-results venues-catalog-results--cards">
        @forelse($venues as $venue)
            @include('theme::pages.venues.partials.catalog-item', ['venue' => $venue, 'mode' => 'card'])
        @empty
            <div class="default-category__empty venues-catalog__empty"><i class="ti ti-map-pin-off" aria-hidden="true"></i><strong>Площадки не найдены</strong><span>Попробуйте изменить условия поиска</span><a class="btn btn--secondary btn--sm" href="{{ route('venues') }}">Сбросить параметры</a></div>
        @endforelse
    </div>
@endsection

@section('category-results-list')
    <div class="default-category-results--list venues-catalog-results venues-catalog-results--list">
        @forelse($venues as $venue)
            @include('theme::pages.venues.partials.catalog-item', ['venue' => $venue, 'mode' => 'list'])
        @empty
            <div class="default-category__empty venues-catalog__empty"><i class="ti ti-map-pin-off" aria-hidden="true"></i><strong>Площадки не найдены</strong><span>Попробуйте изменить условия поиска</span><a class="btn btn--secondary btn--sm" href="{{ route('venues') }}">Сбросить параметры</a></div>
        @endforelse
    </div>
@endsection

@section('category-results-map')
    <section class="venues-catalog-map" data-venue-catalog-map-frame>
        @if($mapVenues->isNotEmpty())
            <p class="venues-catalog-map__status" data-venue-catalog-map-status>Загружаем карту…</p>
            <div class="venues-catalog-map__canvas" data-venue-catalog-map data-yandex-map-api-key="{{ config('integrations.yandex.api_key') }}"></div>
            <script type="application/json" data-venue-catalog-map-points>@json($mapVenues)</script>
        @else
            <div class="default-category__empty venues-catalog__empty"><i class="ti ti-map-pin-off" aria-hidden="true"></i><strong>Нет площадок с координатами</strong><span>Попробуйте изменить условия поиска</span><a class="btn btn--secondary btn--sm" href="{{ route('venues') }}">Сбросить параметры</a></div>
        @endif
    </section>
@endsection

@section('category-mobile-filters')
    @component('theme::partials.modal.layout', ['id' => 'venue-catalog-filters', 'dialogClass' => 'default-category-mobile-filters__dialog'])
        <h2 class="modal_title" id="modal-title-venue-catalog-filters">Фильтры площадок</h2>
        @include('theme::pages.venues.partials.catalog-filters', ['formId' => 'venue-catalog-filter-form-mobile', 'scope' => 'mobile', 'currentView' => $currentView])
    @endcomponent
@endsection
