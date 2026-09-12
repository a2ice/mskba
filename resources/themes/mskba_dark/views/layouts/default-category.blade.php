@php
    $title = $title ?? 'Каталог';
    $categoryId = $categoryId ?? 'default-category';
    $categoryClass = $categoryClass ?? $categoryId;
    $currentView = $currentView ?? 'cards';
    $hasMap = (bool) ($hasMap ?? false);
    $mobileFilterModalId = $mobileFilterModalId ?? $categoryId.'-filters';
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
            <header class="default-category__header">
                <h1>{{ $title }}</h1>
                <button class="page-breadcrumbs__back default-category__back js-handler" type="button" data-handler="historyBack">
                    <i class="ti ti-arrow-left" aria-hidden="true"></i><span>Назад</span>
                </button>
            </header>

            <div class="default-category__grid">
                <aside class="default-category__sidebar" aria-label="Навигация и фильтры раздела">
                    <div
                        class="default-category__navigation"
                        data-mobile-section-sidebar
                        data-mobile-section-sidebar-title="{{ $title }}"
                    >
                        @yield('category-navigation')
                    </div>

                    <div class="default-category__desktop-filters">
                        @yield('category-filters-desktop')
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
                                    <button type="button" role="menuitem" data-default-category-view-option="cards" @class(['is-active' => $currentView === 'cards'])>
                                        <i class="ti ti-layout-grid" aria-hidden="true"></i><span>Карточками</span>
                                    </button>
                                    <button type="button" role="menuitem" data-default-category-view-option="list" @class(['is-active' => $currentView === 'list'])>
                                        <i class="ti ti-list" aria-hidden="true"></i><span>Списком</span>
                                    </button>
                                </div>
                            </div>

                            @if($hasMap)
                                <button
                                    class="default-category-view__button"
                                    type="button"
                                    data-default-category-view-option="map"
                                    @class(['is-active' => $currentView === 'map'])
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
