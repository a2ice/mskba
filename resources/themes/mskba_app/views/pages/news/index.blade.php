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
    $categoryView = request('view') === 'list' ? 'list' : 'cards';
    $categoryBaseUrl = route('news.index');
    $categoryViewQuery = request()->except(['view', 'page', 'fragment']);
    $categoryResetUrl = $categoryBaseUrl.($categoryView === 'list' ? '?view=list' : '');
    $categoryToolbarCount = 'Публикаций: '.$contentItems->total();
@endphp
@extends('theme::layouts.category')

@section('category-sidebar')
    <h2>Новости</h2>
    <nav class="app-category__nav" aria-label="Разделы новостей">
        <a href="{{ route('news.index') }}" @class(['is-active' => !filled($filters['type'] ?? null)])>Все новости</a>
        @foreach($types as $contentType)
            <a href="{{ route('news.index', ['type' => $contentType->value]) }}"
                @class(['is-active' => ($filters['type'] ?? '') === $contentType->value])>{{ $contentType->label() }}</a>
        @endforeach
    </nav>
    <div class="app-category__separator" aria-hidden="true"></div>
    <h2>Фильтры</h2>
    @include('theme::pages.news.partials.category-filters', ['scope' => 'desktop'])
@endsection

@section('category-before-items')
    <details class="app-category__mobile-filters panel">
        <summary>Категории и фильтры <span aria-hidden="true">⌄</span></summary>
        <div class="app-category__mobile-filters-body">
            <h2>Новости</h2>
            <nav class="app-category__nav" aria-label="Разделы новостей">
                <a href="{{ route('news.index') }}" @class(['is-active' => !filled($filters['type'] ?? null)])>Все новости</a>
                @foreach($types as $contentType)
                    <a href="{{ route('news.index', ['type' => $contentType->value]) }}"
                        @class(['is-active' => ($filters['type'] ?? '') === $contentType->value])>{{ $contentType->label() }}</a>
                @endforeach
            </nav>
            <h2>Фильтры</h2>
            @include('theme::pages.news.partials.category-filters', ['scope' => 'mobile'])
        </div>
    </details>
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
                <div class="app-category__badges">
                    <span>{{ $contentItem->type->label() }}</span>
                    @if($contentItem->feed_published_at)
                        <span><time datetime="{{ $contentItem->feed_published_at->toIso8601String() }}">{{ $contentItem->feed_published_at->locale('ru')->translatedFormat('j F Y') }}</time></span>
                    @endif
                </div>
                <h2><a href="{{ $url }}">{{ $contentItem->title }}</a></h2>
                @if($contentItem->short_description)<p class="app-category__description">{{ $contentItem->short_description }}</p>@endif
                <a class="button secondary app-category__details" href="{{ $url }}">Читать материал</a>
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
