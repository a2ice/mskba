@php
    $title = 'Лиги';
    $metaDescription = 'Баскетбольные лиги Москвы: команды, сезоны, турниры и результаты.';
    $categoryTitle = $title;
    $categoryDescription = 'Баскетбольные лиги Москвы: команды, сезоны и турниры.';
    $categoryRootId = 'leagues-catalog';
    $categorySidebarLabel = 'Навигация и фильтры баскетбольных лиг';
    $categoryView = request('view') === 'list' ? 'list' : 'cards';
    $categoryBaseUrl = route('leagues.index');
    $categoryViewQuery = request()->except(['view', 'page', 'fragment']);
    $categoryResetUrl = $categoryBaseUrl.($categoryView === 'list' ? '?view=list' : '');
    $categoryToolbarCount = 'Лиг: '.$leagues->total();
    $canonicalUrl = $leagues->currentPage() > 1 ? route('leagues.index', ['page' => $leagues->currentPage()]) : route('leagues.index');
    $paginationPrevUrl = $leagues->previousPageUrl();
    $paginationNextUrl = $leagues->nextPageUrl();
@endphp
@extends('theme::layouts.category')

@section('category-heading-action')
    @if($canCreateLeague)
        <a class="button primary" href="{{ route('leagues.create') }}">+ Создать лигу</a>
    @endif
@endsection

@section('category-sidebar')
    <h2>Лиги</h2>
    <nav class="app-category__nav" aria-label="Разделы лиг">
        <a href="{{ route('leagues.index') }}" @class(['is-active' => ($filters['status'] ?? '') === ''])>Все лиги</a>
        <a href="{{ route('leagues.index', ['status' => 'active']) }}" @class(['is-active' => ($filters['status'] ?? '') === 'active'])>Действующие</a>
        <a href="{{ route('leagues.index', ['status' => 'archived']) }}" @class(['is-active' => ($filters['status'] ?? '') === 'archived'])>Архивные</a>
    </nav>
    <div class="app-category__separator" aria-hidden="true"></div>
    <h2>Фильтры</h2>
    @include('theme::pages.leagues.partials.category-filters', ['scope' => 'desktop'])
@endsection

@section('category-before-items')
    <details class="app-category__mobile-filters panel">
        <summary>Категории и фильтры <span aria-hidden="true">⌄</span></summary>
        <div class="app-category__mobile-filters-body">
            <h2>Лиги</h2>
            <nav class="app-category__nav" aria-label="Разделы лиг">
                <a href="{{ route('leagues.index') }}" @class(['is-active' => ($filters['status'] ?? '') === ''])>Все лиги</a>
                <a href="{{ route('leagues.index', ['status' => 'active']) }}" @class(['is-active' => ($filters['status'] ?? '') === 'active'])>Действующие</a>
                <a href="{{ route('leagues.index', ['status' => 'archived']) }}" @class(['is-active' => ($filters['status'] ?? '') === 'archived'])>Архивные</a>
            </nav>
            <h2>Фильтры</h2>
            @include('theme::pages.leagues.partials.category-filters', ['scope' => 'mobile'])
        </div>
    </details>
@endsection

@section('category-items')
    @forelse($leagues as $league)
        @php($url = route('leagues.show', $league->alias))
        <article class="panel app-category__card">
            <a class="app-category__card-cover app-category__league-cover" href="{{ $url }}" aria-label="{{ $league->name }}">
                @if($league->logo_path)
                    <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($league->logo_path) }}" loading="lazy" alt="{{ $league->name }}">
                @else
                    <span class="app-category__cover-placeholder" aria-hidden="true"><svg><use href="#ball"></use></svg></span>
                @endif
            </a>
            <div class="app-category__card-body">
                <div class="app-category__badges">
                    <span>{{ $league->status === 'active' ? 'Действующая' : 'В архиве' }}</span>
                </div>
                <h2><a href="{{ $url }}">{{ $league->name }}</a></h2>
                @if($league->description)
                    <p class="app-category__description">{{ $league->description }}</p>
                @endif
                <p>Команд: {{ $league->teams_count }} · Сезонов: {{ $league->seasons_count }}</p>
                <a class="button secondary app-category__details" href="{{ $url }}">Подробнее</a>
            </div>
        </article>
    @empty
        <section class="panel app-category__empty">
            <h2>Лиги не найдены</h2>
            <p>Попробуйте изменить условия поиска.</p>
            <a class="button secondary" href="{{ route('leagues.index') }}">Сбросить параметры</a>
        </section>
    @endforelse
@endsection

@section('category-after-items')
    @if($leagues->hasPages())
        <nav class="app-category__pagination" aria-label="Страницы лиг">{{ $leagues->links() }}</nav>
    @endif
@endsection
