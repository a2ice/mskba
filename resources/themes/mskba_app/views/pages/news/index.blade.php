@php
    $title = 'Новости';
    $feedPage = $contentItems->currentPage();
    $metaTitle = $feedPage > 1 ? 'Новости MSKBA — страница '.$feedPage : 'Новости MSKBA — баскетбол и стритбол';
    $metaDescription = 'Новости баскетбола и стритбола: Москва, команды, площадки, турниры и жизнь сообщества MSKBA.';
    $canonicalUrl = $feedPage > 1 ? route('news.index', ['page' => $feedPage]) : route('news.index');
    $paginationPrevUrl = $contentItems->previousPageUrl();
    $paginationNextUrl = $contentItems->nextPageUrl();
    $categoryTitle = $title;
    $categoryDescription = 'Материалы о баскетболе, площадках и жизни сообщества MSKBA.';
    $categoryRootId = 'news-catalog';
    $categorySidebarLabel = 'Навигация и фильтры новостей';
    $categoryDefaultView = 'list';
    $categoryView = request('view') === 'cards' ? 'cards' : 'list';
    $categoryBaseUrl = route('news.index');
    $categoryViewQuery = request()->except(['view', 'page', 'fragment']);
    $categoryResetUrl = route('news.index', array_filter(['type' => ($filters['type'] ?? '') === 'system_update' ? 'system_update' : null, 'view' => $categoryView === 'cards' ? 'cards' : null]));
    $categoryToolbarCount = 'Публикаций: '.$contentItems->total();
    $categoryCompactToolbar = true;
    $categoryToolbarSearch = ['form' => 'news-category-filter-desktop', 'name' => 'q', 'value' => $filters['q'] ?? '', 'placeholder' => 'Название или описание', 'label' => 'Поиск публикаций', 'maxlength' => 100];
    $categoryVisibleCount = $contentItems->count();
    $categoryResultTotal = $contentItems->total();
@endphp
@extends('theme::layouts.category')

@section('category-sidebar')
    @include('theme::pages.news.partials.sidebar', ['scope' => 'desktop'])
@endsection

@section('category-before-items')
    @include('theme::pages.news.partials.mobile-sidebar')
@endsection

@section('category-items')
    @forelse($contentItems as $contentItem)
        @php($url = route('news.show', $contentItem->alias))
        @php($cover = $contentItem->cover->first())
        <article class="panel app-category__card">
            <a class="app-category__card-cover" href="{{ $url }}" aria-label="{{ $contentItem->title }}">
                @if($cover)
                    <img src="{{ $cover->publicUrl() }}" loading="lazy" alt="{{ $contentItem->title }}">
                @else
                    <span class="app-category__cover-placeholder">MSKBA · Новости</span>
                @endif
            </a>
            <div class="app-category__card-body">
                <h2><a href="{{ $url }}">{{ $contentItem->title }}</a></h2>
                @include('theme::pages.news.partials.metadata')
                @if($contentItem->short_description)<p class="app-category__description">{{ $contentItem->short_description }}</p>@endif
                <a class="button secondary app-category__details" href="{{ $url }}">Подробнее</a>
            </div>
        </article>
    @empty
        <section class="panel app-category__empty">
            <h2>Публикации не найдены</h2>
            <p>Попробуйте изменить условия поиска. Новые материалы появятся после публикации.</p>
            <a class="button secondary" href="{{ route('news.index') }}">Сбросить параметры</a>
        </section>
    @endforelse
@endsection

@section('category-after-items')
    @if($contentItems->hasPages())
        <nav class="app-category__pagination" aria-label="Страницы новостей">{{ $contentItems->links() }}</nav>
    @endif
@endsection
