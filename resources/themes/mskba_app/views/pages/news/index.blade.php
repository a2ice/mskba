@php
    $feedPage = $contentItems->currentPage();
    $title = 'Новости';
    $metaTitle = $feedPage > 1 ? 'Новости MSKBA — страница '.$feedPage : 'Новости MSKBA — баскетбол и стритбол';
    $metaDescription = 'Новости баскетбола и стритбола: Москва, команды, площадки, турниры и жизнь сообщества MSKBA.';
    $canonicalUrl = $feedPage > 1 ? route('news.index', ['page' => $feedPage]) : route('news.index');
    $paginationPrevUrl = $contentItems->previousPageUrl();
    $paginationNextUrl = $contentItems->nextPageUrl();
@endphp
@extends('theme::layouts.app', ['title' => $title])

@section('content')
    <section class="container app-team-section app-team-public" aria-labelledby="news-heading">
        <header class="app-account-overview__intro">
            <h1 id="news-heading">Новости</h1>
            <p>Материалы о баскетболе, площадках и жизни сообщества MSKBA.</p>
        </header>

        <div class="app-team-grid" aria-label="Опубликованные материалы">
            @forelse($contentItems as $contentItem)
                @php($cover = $contentItem->cover->first())
                <article class="panel app-team-card">
                    @if($cover)
                        <a href="{{ route('news.show', $contentItem->alias) }}" aria-label="{{ $contentItem->title }}">
                            <img src="{{ $cover->publicUrl() }}" alt="{{ $contentItem->title }}" loading="lazy" width="480">
                        </a>
                    @endif
                    <div class="app-team-card__sports">
                        <span class="badge">{{ $contentItem->type->label() }}</span>
                        @if($contentItem->feed_published_at)
                            <time datetime="{{ $contentItem->feed_published_at->toIso8601String() }}">{{ $contentItem->feed_published_at->locale('ru')->translatedFormat('j F Y') }}</time>
                        @endif
                    </div>
                    <div class="app-team-card__title">
                        <h2><a href="{{ route('news.show', $contentItem->alias) }}">{{ $contentItem->title }}</a></h2>
                    </div>
                    <p class="app-team-card__detail">{{ $contentItem->short_description }}</p>
                    <a class="button secondary" href="{{ route('news.show', $contentItem->alias) }}">Читать материал</a>
                </article>
            @empty
                <section class="panel app-team-empty">
                    <h2>Публикаций пока нет</h2>
                    <p>Новые материалы появятся здесь после публикации.</p>
                </section>
            @endforelse
        </div>

        @if($contentItems->hasPages())
            <nav class="app-team-pagination" aria-label="Страницы новостей">{{ $contentItems->links() }}</nav>
        @endif
    </section>
@endsection
