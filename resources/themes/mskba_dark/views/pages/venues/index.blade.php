@php
    $title = 'Площадки';
    $currentView = $filters['view'] ?? 'list';
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

@extends('theme::layouts.app', ['title' => $title])

@section('content')
    <section class="venues-catalog first-screen" data-venue-catalog>
        <div class="inner venues-catalog__inner">
            <header class="venues-catalog__header">
                <h1>{{ $title }}</h1>
                <button class="page-breadcrumbs__back venues-catalog__back js-handler" type="button" data-handler="historyBack">
                    <i class="ti ti-arrow-left" aria-hidden="true"></i><span>Назад</span>
                </button>
            </header>

            <div class="catalog-toolbar venues-catalog-toolbar is-filters-collapsed">
                <label class="catalog-toolbar__search" aria-label="Поиск площадок"><i class="ti ti-search" aria-hidden="true"></i><input type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Название, адрес или описание" form="venue-catalog-filter-form"></label>
                <div class="venues-catalog-view" role="group" aria-label="Вид площадок">
                    <button type="button" @class(['is-active' => $currentView === 'list']) data-venue-view="list" aria-label="Список" title="Список" data-tooltip-variant="title" data-tooltip-icon aria-pressed="{{ $currentView === 'list' ? 'true' : 'false' }}"><i class="ti ti-list"></i><span class="catalog-toolbar__button-text">Список</span></button>
                    <button type="button" @class(['is-active' => $currentView === 'map']) data-venue-view="map" aria-label="На карте" title="На карте" data-tooltip-variant="title" data-tooltip-icon aria-pressed="{{ $currentView === 'map' ? 'true' : 'false' }}"><i class="ti ti-map-2"></i><span class="catalog-toolbar__button-text">На карте</span></button>
                </div>
                <button class="btn btn--secondary catalog-toolbar__filter-button venues-catalog__filters-toggle" type="button" data-venue-filter-toggle aria-label="Расширенные фильтры площадок" aria-expanded="false">
                    <i class="ti ti-adjustments-horizontal"></i><span class="catalog-toolbar__button-text">Фильтры</span><i class="ti ti-chevron-down catalog-toolbar__chevron" data-venue-filter-toggle-icon></i>
                    @if($activeFilterCount)<b>{{ $activeFilterCount }}</b>@endif
                </button>
                @auth
                    <a class="btn btn--primary" href="{{ route('venues.create') }}" aria-label="Добавить площадку" title="Добавить площадку" data-tooltip-variant="title" data-tooltip-icon><i class="ti ti-plus"></i><span class="catalog-toolbar__button-text">Добавить</span></a>
                @else
                    <button type="button" class="btn btn--primary js-handler" aria-label="Добавить площадку" title="Добавить площадку" data-tooltip-variant="title" data-tooltip-icon data-handler="modal" data-modal-action="open" data-modal-target="auth-entry-classic" data-auth-redirect-url="{{ route('venues.create', [], false) }}"><i class="ti ti-plus"></i><span class="catalog-toolbar__button-text">Добавить</span></button>
                @endauth
            </div>

            <form id="venue-catalog-filter-form" method="GET" action="{{ route('venues') }}" class="catalog-toolbar__filters venues-catalog-filters" data-venue-filters hidden>
                <input type="hidden" name="view" value="{{ $currentView }}" data-venue-view-input>
                <label><span>Тип площадки</span><select name="type" class="form-select"><option value="">Все типы</option>@foreach($types as $type)<option value="{{ $type->value }}" @selected(($filters['type'] ?? '') === $type->value)>{{ $type->label() }}</option>@endforeach</select></label>
                <label><span>Состояние</span><select name="operational_status" class="form-select"><option value="">Любое</option>@foreach($operationalStatuses as $status)<option value="{{ $status->value }}" @selected(($filters['operational_status'] ?? '') === $status->value)>{{ $status->label() }}</option>@endforeach</select></label>
                <label><span>Доступ</span><select name="access" class="form-select"><option value="">Любой</option><option value="free" @selected(($filters['access'] ?? '') === 'free')>Бесплатно</option><option value="paid" @selected(($filters['access'] ?? '') === 'paid')>Платно</option><option value="approval" @selected(($filters['access'] ?? '') === 'approval')>По подтверждению</option></select></label>
                <div class="venues-catalog-filters__actions"><button class="btn btn--primary" type="submit">Применить</button><a class="btn btn--secondary" href="{{ route('venues', ['view' => $currentView]) }}">Сбросить</a></div>
            </form>

            <div class="venues-catalog-results" data-venue-list @if($currentView === 'map') hidden @endif>
                @forelse($venues as $venue)
                    <article class="catalog-card venue-catalog-card">
                        <a class="catalog-card__image venue-catalog-card__image" href="{{ route('venues.show', $venue->routeIdentifier()) }}"><img src="{{ $venue->imageUrl ?: asset('images/venue-placeholder.png') }}" alt="Фото площадки {{ $venue->name }}"></a>
                        <div class="catalog-card__body venue-catalog-card__body">
                            <div class="catalog-card__badges venue-catalog-card__badges"><span class="catalog-card__badge">{{ $venue->type }}</span><span @class(['catalog-card__badge', 'is-closed' => $venue->operationalStatusSlug !== 'active'])>{{ $venue->operationalStatus }}</span></div>
                            <h2 class="catalog-card__title"><a href="{{ route('venues.show', $venue->routeIdentifier()) }}">{{ $venue->name }}</a></h2>
                            @if($venue->displayAddress)<p><i class="ti ti-map-pin"></i><span>{{ $venue->displayAddress }}</span></p>@endif
                            @if($venue->shortDescription)<p class="catalog-card__description venue-catalog-card__description">{{ $venue->shortDescription }}</p>@endif
                            <div class="venue-catalog-card__access"><span><i class="ti {{ $venue->requiresPayment ? 'ti-currency-ruble' : 'ti-gift' }}"></i>{{ $venue->requiresPayment ? 'Платно' : 'Бесплатно' }}</span>@if($venue->requiresBookingApproval)<span><i class="ti ti-shield-check"></i>По подтверждению</span>@endif</div>
                        </div>
                        <div class="catalog-card__actions venue-catalog-card__actions"><a class="btn btn--secondary btn--sm" href="{{ route('venues.show', $venue->routeIdentifier()) }}">Подробнее<i class="ti ti-arrow-right"></i></a>@if($venue->canEdit)<a class="venue-catalog-card__edit" href="{{ route('account.venues.edit', $venue->routeIdentifier()) }}"><i class="ti ti-pencil"></i>Редактировать</a>@endif</div>
                    </article>
                @empty
                    <div class="venues-catalog__empty"><i class="ti ti-map-pin-off"></i><strong>Площадки не найдены</strong><span>Попробуйте изменить условия поиска</span><a class="btn btn--secondary btn--sm" href="{{ route('venues') }}">Сбросить параметры</a></div>
                @endforelse
            </div>

            <section class="venues-catalog-map" data-venue-catalog-map-frame @if($currentView !== 'map') hidden @endif>
                @if($mapVenues->isNotEmpty())
                    <p class="venues-catalog-map__status" data-venue-catalog-map-status>Загружаем карту…</p>
                    <div class="venues-catalog-map__canvas" data-venue-catalog-map data-yandex-map-api-key="{{ config('integrations.yandex.api_key') }}"></div>
                    <script type="application/json" data-venue-catalog-map-points>@json($mapVenues)</script>
                @else
                    <div class="venues-catalog__empty"><i class="ti ti-map-pin-off"></i><strong>Нет площадок с координатами</strong><span>Попробуйте изменить условия поиска</span><a class="btn btn--secondary btn--sm" href="{{ route('venues') }}">Сбросить параметры</a></div>
                @endif
            </section>
        </div>
    </section>
@endsection
