@php
    $title = $title ?? 'Раздел';
    $sectionId = $sectionId ?? 'section';
    $sectionClass = $sectionClass ?? $sectionId . '-section';
    $contentTitle = $contentTitle ?? $title;
    $contentTitleTooltip = $contentTitleTooltip ?? null;
    $contentSubtitle = $contentSubtitle ?? null;
    $wrapSidebarPanel = $wrapSidebarPanel ?? true;
    $sidebarPartial = $sidebarPartial ?? null;
@endphp

@extends('theme::layouts.app', ['title' => $title])

@section('content')
    <section id="{{ $sectionId }}" class="{{ $sectionClass }} section-sidebar-layout first-screen">
        <div class="inner">
            <div class="mb-3">
                @include('theme::partials.breadcrumbs')
            </div>

            @hasSection('section-mobile-sticky-navigation')
                <div class="section-sidebar-layout__mobile-sticky-navigation">
                    @yield('section-mobile-sticky-navigation')
                </div>
            @endif

            <div class="section-sidebar-layout__grid">
                <aside
                    class="section-sidebar-layout__aside"
                    aria-label="{{ $sidebarLabel ?? 'Навигация раздела' }}"
                    data-mobile-section-sidebar
                    data-mobile-section-sidebar-title="{{ $sidebarLabel ?? 'Навигация раздела' }}"
                >
                    @if($wrapSidebarPanel)
                        <div class="section-sidebar-layout__panel">
                            @hasSection('section-sidebar')
                                @yield('section-sidebar')
                            @elseif($sidebarPartial)
                                @include($sidebarPartial)
                            @endif
                        </div>
                    @else
                        @hasSection('section-sidebar')
                            @yield('section-sidebar')
                        @elseif($sidebarPartial)
                            @include($sidebarPartial)
                        @endif
                    @endif
                </aside>

                <main class="section-sidebar-layout__main">
                    <div class="section-sidebar-layout__panel">
                        <div class="section-sidebar-layout__content">
                            <div class="section-content-heading">
                                <div class="section-content-heading__row">
                                    <div class="section-content-heading__title-group">
                                        @hasSection('section-heading-leading')
                                            @yield('section-heading-leading')
                                        @endif

                                        <h1
                                            class="layout-content-title section-sidebar-layout__title"
                                            @if($contentTitleTooltip)
                                                title="{{ $contentTitleTooltip }}"
                                                data-tooltip-variant="title"
                                            @endif
                                        >{{ $contentTitle }}</h1>
                                    </div>

                                    @hasSection('section-heading-action')
                                        <div class="section-content-heading__action">
                                            @yield('section-heading-action')
                                        </div>
                                    @endif
                                </div>

                                @if($contentSubtitle)
                                    <p class="section-sidebar-layout__subtitle">{{ $contentSubtitle }}</p>
                                @endif
                            </div>

                            @yield('section-content')
                        </div>
                    </div>

                    @if(! empty($contextManagementUrl) && ($contextManagementPlacement ?? 'top') === 'section-after-panel')
                        <div class="mt-3 d-flex justify-content-end" data-context-management-action>
                            <a class="btn btn--secondary btn--sm" href="{{ $contextManagementUrl }}">
                                <i class="ti ti-settings" aria-hidden="true"></i>
                                {{ $contextManagementLabel ?? 'Управление' }}
                            </a>
                        </div>
                    @endif
                </main>
            </div>
        </div>
    </section>
@endsection
