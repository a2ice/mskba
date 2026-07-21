@php
    $title = $title ?? 'Раздел';
    $sectionId = $sectionId ?? 'section';
    $sectionClass = $sectionClass ?? $sectionId . '-section';
    $contentTitle = $contentTitle ?? $title;
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
                                    <h1 class="layout-content-title section-sidebar-layout__title">{{ $contentTitle }}</h1>

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
                </main>
            </div>
        </div>
    </section>
@endsection
