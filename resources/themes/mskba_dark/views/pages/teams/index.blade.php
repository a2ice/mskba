@php
    $title = 'Команды';
    $currentView = in_array(request('view'), ['list', 'map'], true) ? request('view') : 'cards';
    $activeFilterCount = collect([
        filled($filters['member_count'] ?? null),
        filled($filters['sport_type'] ?? null),
        (bool) ($filters['hiring'] ?? false),
    ])->filter()->count();
    $catalogItems = app(\App\Modules\Team\Presentation\Catalog\TeamCatalogPresenter::class)
        ->present($teams->getCollection());
    $mapPoints = $catalogItems
        ->flatMap(function (array $item) {
            return collect($item['confirmed_venues'])
                ->filter(fn (array $venue) => is_numeric($venue['latitude']) && is_numeric($venue['longitude']))
                ->map(fn (array $venue): array => [
                    'latitude' => (float) $venue['latitude'],
                    'longitude' => (float) $venue['longitude'],
                    'venue_name' => $venue['name'],
                    'address' => $venue['address'],
                    'team' => [
                        'id' => $item['id'],
                        'name' => $item['name'],
                        'url' => $item['url'],
                        'sports' => collect($item['sports'])->pluck('label')->implode(', '),
                        'hiring' => $item['hiring_count'] > 0,
                    ],
                ]);
        })
        ->values();
    $mappedTeamIds = $mapPoints->pluck('team.id')->unique()->values();
    $missingMapTeams = $catalogItems->count() - $mappedTeamIds->count();
@endphp

@extends('theme::layouts.default-category', [
    'title' => $title,
    'categoryId' => 'teams',
    'categoryClass' => 'teams-category-catalog',
    'currentView' => $currentView,
    'hasMap' => true,
    'mobileFilterModalId' => 'team-catalog-filters',
])

@section('category-navigation')
    @include('theme::pages.teams.partials.catalog-navigation')
@endsection

@section('category-filters-desktop')
    @include('theme::pages.teams.partials.catalog-filters', [
        'formId' => 'team-catalog-filter-form-desktop',
        'scope' => 'desktop',
        'currentView' => $currentView,
    ])
@endsection

@section('category-search')
    <form id="team-catalog-search-form" method="GET" action="{{ route('teams.index') }}">
        <label class="catalog-toolbar__search" aria-label="Поиск команд">
            <i class="ti ti-search" aria-hidden="true"></i>
            <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="Название или описание" data-default-category-search>
        </label>
        @if(filled($filters['member_count'] ?? null))<input type="hidden" name="member_count" value="{{ $filters['member_count'] }}">@endif
        @if(filled($filters['sport_type'] ?? null))<input type="hidden" name="sport_type" value="{{ $filters['sport_type'] }}">@endif
        @if($filters['hiring'] ?? false)<input type="hidden" name="hiring" value="1">@endif
        <input type="hidden" name="view" value="{{ $currentView === 'cards' ? '' : $currentView }}" data-default-category-view-input @disabled($currentView === 'cards')>
    </form>
@endsection

@section('category-active-filter-count')
    @if($activeFilterCount > 0){{ $activeFilterCount }}@endif
@endsection

@section('category-toolbar-actions')
    @auth
        @can('team-create')
            <a class="btn btn--primary default-category-toolbar__action-button" href="{{ route('teams.create') }}" aria-label="Создать команду" title="Создать команду" data-tooltip-variant="title">
                <i class="ti ti-plus" aria-hidden="true"></i>
            </a>
        @endcan
    @else
        <button
            type="button"
            class="btn btn--primary default-category-toolbar__action-button js-handler"
            aria-label="Создать команду"
            title="Создать команду"
            data-tooltip-variant="title"
            data-handler="modal"
            data-modal-action="open"
            data-modal-target="auth-entry-classic"
            data-auth-redirect-url="{{ route('teams.create', [], false) }}"
        >
            <i class="ti ti-plus" aria-hidden="true"></i>
        </button>
    @endauth
@endsection

@section('category-results-cards')
    <div class="default-category-results--cards team-category-results team-category-results--cards">
        @forelse($catalogItems as $item)
            @include('theme::pages.teams.partials.catalog-item', ['item' => $item, 'mode' => 'card'])
        @empty
            <div class="default-category__empty team-category-catalog__empty">
                <i class="ti ti-users-off" aria-hidden="true"></i>
                <strong>Команды не найдены</strong>
                <span>Попробуйте изменить условия поиска</span>
                <a class="btn btn--secondary btn--sm" href="{{ route('teams.index') }}">Сбросить параметры</a>
            </div>
        @endforelse
    </div>
@endsection

@section('category-results-list')
    <div class="default-category-results--list team-category-results team-category-results--list">
        @forelse($catalogItems as $item)
            @include('theme::pages.teams.partials.catalog-item', ['item' => $item, 'mode' => 'list'])
        @empty
            <div class="default-category__empty team-category-catalog__empty">
                <i class="ti ti-users-off" aria-hidden="true"></i>
                <strong>Команды не найдены</strong>
                <span>Попробуйте изменить условия поиска</span>
                <a class="btn btn--secondary btn--sm" href="{{ route('teams.index') }}">Сбросить параметры</a>
            </div>
        @endforelse
    </div>
@endsection

@section('category-results-map')
    <section class="team-category-map" data-team-category-map-frame>
        @if($mapPoints->isNotEmpty())
            <p class="team-category-map__summary">
                На карте {{ $mappedTeamIds->count() }} из {{ $catalogItems->count() }} команд текущей страницы · точек: {{ $mapPoints->count() }}@if($missingMapTeams > 0); без подтверждённой точки: {{ $missingMapTeams }}@endif
            </p>
            <p class="team-category-map__status" data-team-category-map-status>Загружаем карту…</p>
            <div class="team-category-map__canvas" data-team-category-map data-yandex-map-api-key="{{ config('integrations.yandex.api_key') }}"></div>
            <script type="application/json" data-team-category-map-points>@json($mapPoints)</script>
        @else
            <div class="default-category__empty team-category-catalog__empty">
                <i class="ti ti-map-pin-off" aria-hidden="true"></i>
                <strong>Нет команд с подтверждённой площадкой на карте</strong>
                <span>Желаемые площадки не считаются фактическим местом команды. Все команды остаются доступны в карточках и списке.</span>
            </div>
        @endif
    </section>
@endsection

@section('category-pagination')
    @if($teams->hasPages())
        <div class="team-category-catalog__pagination">{{ $teams->links('theme::partials.pagination') }}</div>
    @endif
@endsection

@section('category-mobile-filters')
    @component('theme::partials.modal.layout', ['id' => 'team-catalog-filters', 'dialogClass' => 'default-category-mobile-filters__dialog'])
        <h2 class="modal_title" id="modal-title-team-catalog-filters">Фильтры команд</h2>
        @include('theme::pages.teams.partials.catalog-filters', [
            'formId' => 'team-catalog-filter-form-mobile',
            'scope' => 'mobile',
            'currentView' => $currentView,
        ])
    @endcomponent
@endsection
