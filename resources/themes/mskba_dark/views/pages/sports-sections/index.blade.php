@php
    $title = 'Секции';
    $currentView = in_array(request('view'), ['list', 'map'], true) ? request('view') : 'cards';
    $activeFilterCount = collect([
        filled($filters['training_mode'] ?? null),
        filled($filters['game_format'] ?? null),
        filled($filters['pricing_type'] ?? null),
        filled($filters['venue_id'] ?? null),
        filled($filters['team_id'] ?? null),
        (bool) ($filters['accepts_requests'] ?? false),
        (bool) ($filters['recruiting'] ?? false),
    ])->filter()->count();
    $catalogItems = app(\App\Modules\SportsSection\Presentation\Catalog\SportsSectionCatalogPresenter::class)
        ->present($sections->getCollection());
    $mapPoints = $catalogItems
        ->filter(fn (array $item) => $item['venue'] !== null
            && is_numeric($item['venue']['latitude'])
            && is_numeric($item['venue']['longitude']))
        ->map(fn (array $item): array => [
            'latitude' => (float) $item['venue']['latitude'],
            'longitude' => (float) $item['venue']['longitude'],
            'venue_name' => $item['venue']['name'],
            'address' => $item['venue']['address'],
            'section' => [
                'id' => $item['id'],
                'name' => $item['name'],
                'url' => $item['url'],
                'training_mode' => $item['training_mode']['label'],
                'format' => $item['format']['label'],
                'pricing' => $item['pricing_text'],
                'recruiting' => $item['recruiting'],
                'accepts_requests' => $item['accepts_requests'],
                'recruitment_text' => $item['recruitment_text'],
            ],
        ])
        ->values();
    $mappedSectionIds = $mapPoints->pluck('section.id')->unique()->values();
    $missingMapSections = $catalogItems->count() - $mappedSectionIds->count();
@endphp

@extends('theme::layouts.default-category', [
    'title' => $title,
    'categoryId' => 'sports-sections',
    'categoryClass' => 'sports-sections-category-catalog',
    'currentView' => $currentView,
    'hasMap' => true,
    'mobileFilterModalId' => 'sports-section-catalog-filters',
])

@section('category-navigation')
    @include('theme::pages.sports-sections.partials.catalog-navigation')
@endsection

@section('category-filters-desktop')
    @include('theme::pages.sports-sections.partials.catalog-filters', [
        'formId' => 'sports-section-catalog-filter-form-desktop',
        'scope' => 'desktop',
        'currentView' => $currentView,
    ])
@endsection

@section('category-search')
    <form id="sports-section-catalog-search-form" method="GET" action="{{ route('sports-sections.index') }}">
        <label class="catalog-toolbar__search" aria-label="Поиск спортивных секций">
            <i class="ti ti-search" aria-hidden="true"></i>
            <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="Название или описание" data-default-category-search>
        </label>
        @if(filled($filters['training_mode'] ?? null))<input type="hidden" name="training_mode" value="{{ $filters['training_mode'] }}">@endif
        @if(filled($filters['game_format'] ?? null))<input type="hidden" name="game_format" value="{{ $filters['game_format'] }}">@endif
        @if(filled($filters['pricing_type'] ?? null))<input type="hidden" name="pricing_type" value="{{ $filters['pricing_type'] }}">@endif
        @if(filled($filters['venue_id'] ?? null))<input type="hidden" name="venue_id" value="{{ $filters['venue_id'] }}">@endif
        @if(filled($filters['team_id'] ?? null))<input type="hidden" name="team_id" value="{{ $filters['team_id'] }}">@endif
        @if($filters['accepts_requests'] ?? false)<input type="hidden" name="accepts_requests" value="1">@endif
        @if($filters['recruiting'] ?? false)<input type="hidden" name="recruiting" value="1">@endif
        <input type="hidden" name="view" value="{{ $currentView === 'cards' ? '' : $currentView }}" data-default-category-view-input @disabled($currentView === 'cards')>
    </form>
@endsection

@section('category-active-filter-count')
    @if($activeFilterCount > 0){{ $activeFilterCount }}@endif
@endsection

@section('category-toolbar-actions')
    <a class="btn btn--primary default-category-toolbar__action-button" href="{{ route('account.sports-sections.create') }}" aria-label="Создать секцию" title="Создать секцию" data-tooltip-variant="title"><i class="ti ti-plus" aria-hidden="true"></i></a>
@endsection

@section('category-results-cards')
    <div class="default-category-results--cards sports-section-category-results sports-section-category-results--cards">
        @forelse($catalogItems as $item)
            @include('theme::pages.sports-sections.partials.catalog-item', ['item' => $item, 'mode' => 'card'])
        @empty
            <div class="default-category__empty sports-section-category-catalog__empty">
                <i class="ti ti-ball-basketball-off" aria-hidden="true"></i>
                <strong>Секции не найдены</strong>
                <span>Попробуйте изменить условия поиска</span>
                <a class="btn btn--secondary btn--sm" href="{{ route('sports-sections.index') }}">Сбросить параметры</a>
            </div>
        @endforelse
    </div>
@endsection

@section('category-results-list')
    <div class="default-category-results--list sports-section-category-results sports-section-category-results--list">
        @forelse($catalogItems as $item)
            @include('theme::pages.sports-sections.partials.catalog-item', ['item' => $item, 'mode' => 'list'])
        @empty
            <div class="default-category__empty sports-section-category-catalog__empty">
                <i class="ti ti-ball-basketball-off" aria-hidden="true"></i>
                <strong>Секции не найдены</strong>
                <span>Попробуйте изменить условия поиска</span>
                <a class="btn btn--secondary btn--sm" href="{{ route('sports-sections.index') }}">Сбросить параметры</a>
            </div>
        @endforelse
    </div>
@endsection

@section('category-results-map')
    <section class="sports-section-category-map" data-sports-section-category-map-frame>
        @if($mapPoints->isNotEmpty())
            <p class="sports-section-category-map__summary">
                На карте {{ $mappedSectionIds->count() }} из {{ $catalogItems->count() }} секций текущей страницы@if($missingMapSections > 0) · без координат: {{ $missingMapSections }}@endif
            </p>
            <p class="sports-section-category-map__status" data-sports-section-category-map-status>Загружаем карту…</p>
            <div class="sports-section-category-map__canvas" data-sports-section-category-map data-yandex-map-api-key="{{ config('integrations.yandex.api_key') }}"></div>
            <script type="application/json" data-sports-section-category-map-points>@json($mapPoints)</script>
        @else
            <div class="default-category__empty sports-section-category-catalog__empty">
                <i class="ti ti-map-pin-off" aria-hidden="true"></i>
                <strong>Нет секций с координатами основной площадки</strong>
                <span>Секции без площадки или координат остаются доступны в карточках и списке.</span>
            </div>
        @endif
    </section>
@endsection

@section('category-pagination')
    @if($sections->hasPages())
        <div class="sports-section-category-catalog__pagination">{{ $sections->links('theme::partials.pagination') }}</div>
    @endif
@endsection

@section('category-mobile-filters')
    @component('theme::partials.modal.layout', ['id' => 'sports-section-catalog-filters', 'dialogClass' => 'default-category-mobile-filters__dialog'])
        <h2 class="modal_title" id="modal-title-sports-section-catalog-filters">Фильтры секций</h2>
        @include('theme::pages.sports-sections.partials.catalog-filters', [
            'formId' => 'sports-section-catalog-filter-form-mobile',
            'scope' => 'mobile',
            'currentView' => $currentView,
        ])
    @endcomponent
@endsection
