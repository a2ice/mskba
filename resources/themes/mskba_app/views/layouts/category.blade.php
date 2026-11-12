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
    $categorySidebarLabel = $categorySidebarLabel ?? 'Навигация и фильтры';
    $categoryToolbarCount = $categoryToolbarCount ?? null;
@endphp
@extends('theme::layouts.app', ['title' => $categoryTitle, 'breadcrumbs' => $breadcrumbs ?? []])

@section('content')
<section class="container {{ $categoryRootClass }}" id="{{ $categoryRootId }}"
    data-default-category data-default-category-view="{{ $categoryView }}"
    @if($categoryRootId === 'venues-catalog') data-venue-infinite-catalog data-next-page="{{ $hasMore ? $page + 1 : '' }}" @endif
    aria-labelledby="{{ $categoryRootId }}-title">
    <header class="{{ $categoryHeaderClass }}">
        <div>
            <h1 id="{{ $categoryRootId }}-title">{{ $categoryTitle }}</h1>
            @if($categoryDescription !== '')<p class="muted">{{ $categoryDescription }}</p>@endif
        </div>
        @yield('category-heading-action')
    </header>
    <div class="{{ $categoryLayoutClass }}">
        <aside class="{{ $categorySidebarClass }} panel" aria-label="{{ $categorySidebarLabel }}">
            @yield('category-sidebar')
        </aside>
        <main class="{{ $categoryMainClass }}">
            <div class="{{ $categoryToolbarClass }}">
                @hasSection('category-toolbar-count')
                    @yield('category-toolbar-count')
                @else
                    <span class="app-category__count" role="status">{{ $categoryToolbarCount }}</span>
                @endif
                @include('theme::components.catalog-toolbar', [
                    'baseUrl' => $categoryBaseUrl,
                    'viewQuery' => $categoryViewQuery,
                    'currentView' => $categoryView,
                    'resetUrl' => $categoryResetUrl,
                    'enableSorting' => $categoryEnableSorting ?? false,
                    'sortValue' => $categorySortValue ?? '',
                    'sortChoices' => $categorySortChoices ?? null,
                ])
                @yield('category-toolbar-extra')
            </div>
            @yield('category-before-items')
            <div class="{{ $categoryItemsClass }}{{ $categoryView === 'list' ? ' '.$categoryItemsListClass : '' }}"
                data-default-category-results="{{ $categoryView }}">
                @yield('category-items')
            </div>
            @yield('category-after-items')
        </main>
    </div>
</section>
@endsection
