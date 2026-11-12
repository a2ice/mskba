@php
    $title = 'Турниры';
    $metaDescription = 'Баскетбольные и стритбольные турниры Москвы: участники, матчи, результаты и турнирные таблицы.';
    $categoryTitle = $title;
    $categoryDescription = 'Турниры по баскетболу и стритболу, расписания и результаты.';
    $categoryRootId = 'tournaments-catalog';
    $categorySidebarLabel = 'Навигация и фильтры турниров';
    $categoryView = request('view') === 'list' ? 'list' : 'cards';
    $categoryBaseUrl = route('tournaments.index');
    $categoryViewQuery = request()->except(['view', 'page', 'fragment']);
    $categoryResetUrl = $categoryBaseUrl.($categoryView === 'list' ? '?view=list' : '');
    $categoryToolbarCount = 'Турниров: '.$tournaments->total();
    $canonicalUrl = $tournaments->currentPage() > 1 ? route('tournaments.index', ['page' => $tournaments->currentPage()]) : route('tournaments.index');
    $paginationPrevUrl = $tournaments->previousPageUrl();
    $paginationNextUrl = $tournaments->nextPageUrl();
@endphp
@extends('theme::layouts.category')

@section('category-heading-action')
    <a class="button primary" href="{{ route('tournaments.create') }}">+ Создать турнир</a>
@endsection

@section('category-sidebar')
    <h2>Турниры</h2>
    <nav class="app-category__nav" aria-label="Разделы турниров">
        <a href="{{ route('tournaments.index') }}" @class(['is-active' => $period === 'all'])>Все турниры</a>
        <a href="{{ route('tournaments.index', ['period' => 'current']) }}" @class(['is-active' => $period === 'current'])>Текущие</a>
        <a href="{{ route('tournaments.index', ['period' => 'upcoming']) }}" @class(['is-active' => $period === 'upcoming'])>Предстоящие</a>
        <a href="{{ route('tournaments.index', ['period' => 'past']) }}" @class(['is-active' => $period === 'past'])>Прошедшие</a>
    </nav>
    <div class="app-category__separator" aria-hidden="true"></div>
    <h2>Фильтры</h2>
    @include('theme::pages.tournaments.partials.category-filters', ['scope' => 'desktop'])
@endsection

@section('category-before-items')
    <details class="app-category__mobile-filters panel">
        <summary>Категории и фильтры <span aria-hidden="true">⌄</span></summary>
        <div class="app-category__mobile-filters-body">
            <h2>Турниры</h2>
            <nav class="app-category__nav" aria-label="Разделы турниров">
                <a href="{{ route('tournaments.index') }}" @class(['is-active' => $period === 'all'])>Все турниры</a>
                <a href="{{ route('tournaments.index', ['period' => 'current']) }}" @class(['is-active' => $period === 'current'])>Текущие</a>
                <a href="{{ route('tournaments.index', ['period' => 'upcoming']) }}" @class(['is-active' => $period === 'upcoming'])>Предстоящие</a>
                <a href="{{ route('tournaments.index', ['period' => 'past']) }}" @class(['is-active' => $period === 'past'])>Прошедшие</a>
            </nav>
            <h2>Фильтры</h2>
            @include('theme::pages.tournaments.partials.category-filters', ['scope' => 'mobile'])
        </div>
    </details>
@endsection

@section('category-items')
    @forelse($tournaments as $tournament)
        @php($url = route('tournaments.show', $tournament->routeIdentifier()))
        <article class="panel app-category__card">
            <a class="app-category__card-cover" href="{{ $url }}" aria-label="{{ $tournament->title }}">
                <img src="{{ $tournament->cover?->publicUrl() ?: asset('images/venue-placeholder.png') }}" loading="lazy" alt="{{ $tournament->title }}">
            </a>
            <div class="app-category__card-body">
                <div class="app-category__badges">
                    <span>{{ $tournament->status->label() }}</span>
                    <span>{{ $tournament->phase()->label() }}</span>
                    @if($tournament->format)<span>{{ $tournament->format->label() }}</span>@endif
                </div>
                <h2><a href="{{ $url }}">{{ $tournament->title }}</a></h2>
                <p>{{ $tournament->starts_on->format('d.m.Y') }}@if($tournament->ends_on) — {{ $tournament->ends_on->format('d.m.Y') }}@endif</p>
                @if($tournament->defaultVenue)<p>{{ $tournament->defaultVenue->name }}</p>@endif
                @if($tournament->recruitment_mode)<p>{{ $tournament->recruitment_mode->label() }}</p>@endif
                @if($tournament->short_description)<p class="app-category__description">{{ $tournament->short_description }}</p>@endif
                <a class="button secondary app-category__details" href="{{ $url }}">Подробнее</a>
            </div>
        </article>
    @empty
        <section class="panel app-category__empty">
            <h2>Турниры не найдены</h2>
            <p>Попробуйте изменить условия поиска.</p>
            <a class="button secondary" href="{{ route('tournaments.index') }}">Сбросить параметры</a>
        </section>
    @endforelse
@endsection

@section('category-after-items')
    @if($tournaments->hasPages())
        <nav class="app-category__pagination" aria-label="Страницы турниров">{{ $tournaments->links() }}</nav>
    @endif
@endsection
