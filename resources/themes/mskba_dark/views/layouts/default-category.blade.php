@php
    $title = $title ?? 'Каталог';
    $categoryId = $categoryId ?? 'default-category';
    $categoryClass = $categoryClass ?? $categoryId;
    $currentView = $currentView ?? 'cards';
    $hasMap = (bool) ($hasMap ?? false);
    $mobileFilterModalId = $mobileFilterModalId ?? $categoryId.'-filters';
    $sidebarNavigationTitle = $sidebarNavigationTitle ?? $title;
    $sidebarFiltersTitle = $sidebarFiltersTitle ?? 'Фильтры';
@endphp

@extends('theme::layouts.app', ['title' => $title])

@section('content')
    <section
        id="{{ $categoryId }}"
        class="default-category {{ $categoryClass }} first-screen"
        data-default-category
        data-default-category-view="{{ $currentView }}"
    >
        <div class="inner default-category__inner">
            <div class="default-category__breadcrumbs">
                @include('theme::partials.breadcrumbs')
            </div>

            <header class="default-category__header">
                <h1>{{ $title }}</h1>
            </header>

            <div class="default-category__grid">
                <aside class="default-category__sidebar" aria-label="Навигация и фильтры раздела">
                    <div class="default-category__sidebar-panel" data-default-category-sidebar-accordion>
                        <section class="default-category-sidebar-accordion__item is-open" data-default-category-sidebar-item>
                            <button
                                class="default-category-sidebar-accordion__trigger"
                                type="button"
                                aria-expanded="true"
                                aria-controls="{{ $categoryId }}-sidebar-navigation"
                                data-default-category-sidebar-trigger
                            >
                                <span>{{ $sidebarNavigationTitle }}</span>
                                <i class="ti ti-chevron-down" aria-hidden="true"></i>
                            </button>
                            <div
                                id="{{ $categoryId }}-sidebar-navigation"
                                class="default-category-sidebar-accordion__content"
                                data-default-category-sidebar-content
                            >
                                <div
                                    class="default-category__navigation"
                                    data-mobile-section-sidebar
                                    data-mobile-section-sidebar-title="{{ $title }}"
                                >
                                    @yield('category-navigation')
                                </div>
                            </div>
                        </section>

                        <section class="default-category-sidebar-accordion__item" data-default-category-sidebar-item>
                            <button
                                class="default-category-sidebar-accordion__trigger"
                                type="button"
                                aria-expanded="false"
                                aria-controls="{{ $categoryId }}-sidebar-filters"
                                data-default-category-sidebar-trigger
                            >
                                <span>{{ $sidebarFiltersTitle }}</span>
                                <i class="ti ti-chevron-down" aria-hidden="true"></i>
                            </button>
                            <div
                                id="{{ $categoryId }}-sidebar-filters"
                                class="default-category-sidebar-accordion__content"
                                data-default-category-sidebar-content
                                hidden
                            >
                                <div class="default-category__desktop-filters">
                                    @yield('category-filters-desktop')
                                </div>
                            </div>
                        </section>
                    </div>
                </aside>

                <main class="default-category__main">
                    <div class="default-category-toolbar" data-default-category-toolbar>
                        <div class="default-category-toolbar__search">
                            @yield('category-search')
                        </div>

                        <div class="default-category-view" data-default-category-view-switcher>
                            <div class="default-category-view__menu">
                                <button
                                    class="default-category-view__button"
                                    type="button"
                                    data-default-category-view-menu-toggle
                                    aria-haspopup="menu"
                                    aria-expanded="false"
                                    aria-label="Вид результатов"
                                    title="Вид результатов"
                                    data-tooltip-variant="title"
                                >
                                    <i class="ti {{ $currentView === 'list' ? 'ti-list' : 'ti-layout-grid' }}" data-default-category-view-icon aria-hidden="true"></i>
                                </button>
                                <div class="default-category-view__dropdown" role="menu" data-default-category-view-menu hidden>
                                    <button @class(['is-active' => $currentView === 'cards']) type="button" role="menuitem" data-default-category-view-option="cards">
                                        <i class="ti ti-layout-grid" aria-hidden="true"></i><span>Карточками</span>
                                    </button>
                                    <button @class(['is-active' => $currentView === 'list']) type="button" role="menuitem" data-default-category-view-option="list">
                                        <i class="ti ti-list" aria-hidden="true"></i><span>Списком</span>
                                    </button>
                                </div>
                            </div>

                            @if($hasMap)
                                <button
                                    @class(['default-category-view__button', 'is-active' => $currentView === 'map'])
                                    type="button"
                                    data-default-category-view-option="map"
                                    aria-label="На карте"
                                    title="На карте"
                                    data-tooltip-variant="title"
                                >
                                    <i class="ti ti-map-2" aria-hidden="true"></i>
                                </button>
                            @endif
                        </div>

                        <button
                            class="default-category-toolbar__mobile-filter js-handler"
                            type="button"
                            data-handler="modal"
                            data-modal-action="open"
                            data-modal-target="{{ $mobileFilterModalId }}"
                            aria-label="Фильтры"
                            title="Фильтры"
                            data-tooltip-variant="title"
                        >
                            <i class="ti ti-adjustments-horizontal" aria-hidden="true"></i>
                            @hasSection('category-active-filter-count')
                                <b>@yield('category-active-filter-count')</b>
                            @endif
                        </button>

                        <div class="default-category-toolbar__actions">
                            @yield('category-toolbar-actions')
                        </div>
                    </div>

                    <div class="default-category__results">
                        <div data-default-category-results="cards" @if($currentView !== 'cards') hidden @endif>
                            @yield('category-results-cards')
                        </div>
                        <div data-default-category-results="list" @if($currentView !== 'list') hidden @endif>
                            @yield('category-results-list')
                        </div>
                        @if($hasMap)
                            <div data-default-category-results="map" @if($currentView !== 'map') hidden @endif>
                                @yield('category-results-map')
                            </div>
                        @endif
                    </div>

                    @yield('category-pagination')
                </main>
            </div>
        </div>
    </section>

    @yield('category-mobile-filters')
@endsection
