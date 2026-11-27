{{-- Shared light-theme catalog shell. Each entity owns only its navigation,
     filters, cards, and domain-specific capabilities; never load dark layouts. --}}
@php
    $categoryTitle = $categoryTitle ?? $title ?? 'Каталог';
    $categoryDescription = $categoryDescription ?? '';
    $categoryRootClass = $categoryRootClass ?? 'app-category';
    $categoryHeaderClass = $categoryHeaderClass ?? 'app-category__header';
    $categoryLayoutClass = $categoryLayoutClass ?? 'app-category__layout';
    $categorySidebarClass = $categorySidebarClass ?? 'app-category__sidebar';
    $categoryMainClass = $categoryMainClass ?? 'app-category__main';
    $categoryToolbarClass = $categoryToolbarClass ?? 'app-category__toolbar';
    $categoryItemsClass = $categoryItemsClass ?? 'app-category__items';
    $categoryItemsListClass = $categoryItemsListClass ?? 'app-category__items--list';
    $categoryView = $categoryView ?? (request('view') === 'list' ? 'list' : 'cards');
    $categoryBaseUrl = $categoryBaseUrl ?? url()->current();
    $categoryViewQuery = $categoryViewQuery ?? request()->except(['view', 'page', 'fragment']);
    $categoryResetUrl = $categoryResetUrl ?? $categoryBaseUrl;
    $categoryRootId = $categoryRootId ?? 'mskba-category';
    $categoryHeadingId = $categoryHeadingId ?? $categoryRootId.'-title';
    $categorySidebarLabel = $categorySidebarLabel ?? 'Навигация и фильтры';
    $categoryToolbarCount = $categoryToolbarCount ?? null;
@endphp
@extends('theme::layouts.app', ['title' => $categoryTitle, 'breadcrumbs' => $breadcrumbs ?? []])

@section('content')
<section class="container {{ $categoryRootClass }}" id="{{ $categoryRootId }}"
    data-default-category data-default-category-view="{{ $categoryView }}"
    data-context-result-total="{{ $categoryResultTotal ?? '' }}"
    @if($categoryRootId === 'venues-catalog' && $categoryView !== 'map') data-venue-infinite-catalog data-next-page="{{ $hasMore ? $page + 1 : '' }}" @endif
    aria-labelledby="{{ $categoryHeadingId }}">
    <header class="{{ $categoryHeaderClass }}">
        <div>
            <h1 id="{{ $categoryHeadingId }}">{{ $categoryTitle }}</h1>
            @if($isLandingPreview ?? false)<p class="muted">Предпросмотр сохранённой посадочной. Не опубликована этим действием.</p>@endif
            @if($categoryDescription !== '' && !($categoryRootId === 'venues-catalog' && isset($landing) && (($isLandingPreview ?? false) || ($landing->is_active && config('features.venue_catalog_landings.enabled', false))) && (int) ($page ?? 1) === 1 && filled($landing->intro_text)))<p class="muted">{{ $categoryDescription }}</p>@endif
        </div>
        @yield('category-heading-action')
        @if($categoryRootId === 'venues-catalog')
            @include('partials.venues.catalog-landing-editorial', [
                'editorialPosition' => 'intro',
                'editorialClass' => 'venue-catalog-editorial venue-catalog-editorial--intro muted',
                'editorialPage' => $page ?? 1,
            ])
        @endif
    </header>
    <div class="{{ $categoryLayoutClass }}">
        <aside class="{{ $categorySidebarClass }} panel" aria-label="{{ $categorySidebarLabel }}">
            @yield('category-sidebar')
        </aside>
        <main class="{{ $categoryMainClass }}">
            <div class="{{ $categoryToolbarClass }}{{ ($categoryCompactToolbar ?? false) ? ' panel app-category-toolbar--compact' : '' }}">
                @if(isset($categoryToolbarSearch))
                    <input class="app-category-toolbar__search app-category-toolbar__search--desktop" type="search"
                        form="{{ $categoryToolbarSearch['form'] }}" name="{{ $categoryToolbarSearch['name'] }}"
                        value="{{ $categoryToolbarSearch['value'] }}" placeholder="{{ $categoryToolbarSearch['placeholder'] }}"
                        aria-label="{{ $categoryToolbarSearch['label'] }}" enterkeyhint="search" @if(isset($categoryToolbarSearch['maxlength'])) maxlength="{{ $categoryToolbarSearch['maxlength'] }}" @endif>
                @endif
                @yield('category-toolbar-search')
                @hasSection('category-toolbar-count')
                    @yield('category-toolbar-count')
                @elseif(isset($categoryVisibleCount, $categoryResultTotal))
                    <span class="app-category-toolbar__count" role="status" title="Показано / всего">{{ $categoryVisibleCount }}/{{ $categoryResultTotal }}</span>
                @else
                    <span class="app-category__count" role="status">{{ $categoryToolbarCount }}</span>
                @endif
                @include('theme::components.catalog-toolbar', [
                    'baseUrl' => $categoryBaseUrl,
                    'viewQuery' => $categoryViewQuery,
                    'currentView' => $categoryView,
                    'enableMapView' => $categoryHasMap ?? false,
                    'resetUrl' => $categoryResetUrl,
                    'showReset' => !($categoryCompactToolbar ?? false),
                    'enableSorting' => $categoryEnableSorting ?? false,
                    'sortValue' => $categorySortValue ?? '',
                    'sortChoices' => $categorySortChoices ?? null,
                ])
                @yield('category-toolbar-extra')
            </div>
            @yield('category-before-items')
            @if($categoryView === 'map' && ($categoryHasMap ?? false))
                @yield('category-map')
            @else
                <div class="{{ $categoryItemsClass }}{{ $categoryView === 'list' ? ' '.$categoryItemsListClass : '' }}"
                    data-default-category-results="{{ $categoryView }}"
                    @if($categoryRootId === 'venues-catalog') data-venue-items @endif>
                    @yield('category-items')
                </div>
                @yield('category-after-items')
            @endif
        </main>
    </div>
    @if($categoryRootId === 'venues-catalog')
        @include('partials.venues.catalog-landing-editorial', [
            'editorialPosition' => 'body',
            'editorialClass' => 'venue-catalog-editorial app-venue-description',
            'editorialPage' => $page ?? 1,
        ])
    @endif
</section>
@endsection
