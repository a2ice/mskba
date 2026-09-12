@php
    $title = 'Турниры';
    $currentView = in_array(request('view'), ['list', 'map'], true) ? request('view') : 'cards';
    $activeFilterCount = collect([
        filled($dateFrom),
        filled($dateTo),
    ])->filter()->count();
    $mapTournaments = collect($tournaments->items())
        ->filter(fn ($tournament) => $tournament->defaultVenue?->location?->address?->latitude !== null && $tournament->defaultVenue?->location?->address?->longitude !== null)
        ->map(function ($tournament) {
            $venue = $tournament->defaultVenue;
            $address = $venue->raw_address ?: $venue->location?->address?->full_address;

            return [
                'latitude' => (float) $venue->location->address->latitude,
                'longitude' => (float) $venue->location->address->longitude,
                'venue_name' => $venue->name,
                'address' => $address,
                'tournament' => [
                    'title' => $tournament->title,
                    'phase' => $tournament->phase()->label(),
                    'dates' => $tournament->starts_on->format('d.m.Y').($tournament->ends_on ? ' — '.$tournament->ends_on->format('d.m.Y') : ''),
                    'url' => route('tournaments.show', $tournament->routeIdentifier()),
                ],
            ];
        })->values();
    $missingMapPoints = $tournaments->count() - $mapTournaments->count();
@endphp

@extends('theme::layouts.default-category', [
    'title' => $title,
    'categoryId' => 'tournaments',
    'categoryClass' => 'tournaments-category-catalog',
    'currentView' => $currentView,
    'hasMap' => true,
    'mobileFilterModalId' => 'tournament-catalog-filters',
])

@section('category-navigation')
    @include('theme::pages.tournaments.partials.catalog-navigation')
@endsection

@section('category-filters-desktop')
    @include('theme::pages.tournaments.partials.catalog-filters', [
        'formId' => 'tournament-catalog-filter-form-desktop',
        'scope' => 'desktop',
        'currentView' => $currentView,
    ])
@endsection

@section('category-search')
    <form id="tournament-catalog-search-form" method="GET" action="{{ route('tournaments.index') }}">
        <label class="catalog-toolbar__search" aria-label="Поиск турниров">
            <i class="ti ti-search" aria-hidden="true"></i>
            <input type="search" name="query" value="{{ $query }}" placeholder="Название турнира" data-default-category-search>
        </label>
        @if($period !== 'all')<input type="hidden" name="period" value="{{ $period }}">@endif
        @if(filled($dateFrom))<input type="hidden" name="date_from" value="{{ $dateFrom }}">@endif
        @if(filled($dateTo))<input type="hidden" name="date_to" value="{{ $dateTo }}">@endif
        <input type="hidden" name="view" value="{{ $currentView === 'cards' ? '' : $currentView }}" data-default-category-view-input @disabled($currentView === 'cards')>
    </form>
@endsection

@section('category-active-filter-count')
    @if($activeFilterCount > 0){{ $activeFilterCount }}@endif
@endsection

@section('category-toolbar-actions')
    @if(auth()->user()?->status === \App\Modules\Identity\Domain\Enums\UserStatusEnum::CONFIRMED)
        <a class="btn btn--primary default-category-toolbar__action-button" href="{{ route('tournaments.create') }}" aria-label="Создать турнир" title="Создать турнир" data-tooltip-variant="title">
            <i class="ti ti-plus" aria-hidden="true"></i>
        </a>
    @endif
@endsection

@section('category-results-cards')
    <div class="default-category-results--cards tournament-category-results tournament-category-results--cards">
        @forelse($tournaments as $tournament)
            @include('theme::pages.tournaments.partials.catalog-item', ['tournament' => $tournament, 'mode' => 'card'])
        @empty
            <div class="default-category__empty tournament-category-catalog__empty"><i class="ti ti-trophy-off" aria-hidden="true"></i><strong>Турниры не найдены</strong><span>Попробуйте изменить условия поиска</span><a class="btn btn--secondary btn--sm" href="{{ route('tournaments.index') }}">Сбросить параметры</a></div>
        @endforelse
    </div>
@endsection

@section('category-results-list')
    <div class="default-category-results--list tournament-category-results tournament-category-results--list">
        @forelse($tournaments as $tournament)
            @include('theme::pages.tournaments.partials.catalog-item', ['tournament' => $tournament, 'mode' => 'list'])
        @empty
            <div class="default-category__empty tournament-category-catalog__empty"><i class="ti ti-trophy-off" aria-hidden="true"></i><strong>Турниры не найдены</strong><span>Попробуйте изменить условия поиска</span><a class="btn btn--secondary btn--sm" href="{{ route('tournaments.index') }}">Сбросить параметры</a></div>
        @endforelse
    </div>
@endsection

@section('category-results-map')
    <section class="tournament-category-map" data-tournament-category-map-frame>
        @if($mapTournaments->isNotEmpty())
            <p class="tournament-category-map__summary">На карте {{ $mapTournaments->count() }} из {{ $tournaments->count() }} турниров текущей страницы@if($missingMapPoints > 0); без координат: {{ $missingMapPoints }}@endif</p>
            <p class="tournament-category-map__status" data-tournament-category-map-status>Загружаем карту…</p>
            <div class="tournament-category-map__canvas" data-tournament-category-map data-yandex-map-api-key="{{ config('integrations.yandex.api_key') }}"></div>
            <script type="application/json" data-tournament-category-map-points>@json($mapTournaments)</script>
        @else
            <div class="default-category__empty tournament-category-catalog__empty"><i class="ti ti-map-pin-off" aria-hidden="true"></i><strong>Нет турниров с координатами площадки</strong><span>В карточках и списке турниры без точки остаются доступны</span></div>
        @endif
    </section>
@endsection

@section('category-pagination')
    @if($tournaments->hasPages())
        <div class="tournament-category-catalog__pagination">{{ $tournaments->links('theme::partials.pagination') }}</div>
    @endif
@endsection

@section('category-mobile-filters')
    @component('theme::partials.modal.layout', ['id' => 'tournament-catalog-filters', 'dialogClass' => 'default-category-mobile-filters__dialog'])
        <h2 class="modal_title" id="modal-title-tournament-catalog-filters">Фильтры турниров</h2>
        @include('theme::pages.tournaments.partials.catalog-filters', ['formId' => 'tournament-catalog-filter-form-mobile', 'scope' => 'mobile', 'currentView' => $currentView])
    @endcomponent
@endsection
