@php
    $pageNumber = $contentItems->currentPage();
    $title = 'Новости';
    $metaTitle = $pageNumber > 1 ? 'Новости MSKBA — страница '.$pageNumber : 'Новости MSKBA — баскетбол и стритбол';
    $metaDescription = 'Новости баскетбола и стритбола: Москва, команды, площадки, турниры и жизнь сообщества MSKBA.';
    $canonicalUrl = $pageNumber > 1 ? route('news.index', ['page' => $pageNumber]) : route('news.index');
    $paginationPrevUrl = $contentItems->previousPageUrl();
    $paginationNextUrl = $contentItems->nextPageUrl();
@endphp
@extends('theme::layouts.app', ['title' => $title])

@section('content')
<section class="container app-content-page" aria-labelledby="feed-heading">
    <header class="app-account-overview__intro">
        <h1 id="feed-heading">Новости</h1>
        <p>Материалы о баскетболе, площадках и жизни сообщества MSKBA.</p>
    </header>
    <div class="app-content-page__grid">
        @forelse($contentItems as $contentItem)
            <article class="panel app-content-page__card">
                @php($cover = $contentItem->cover->first())
                @if($cover)
                    <a href="{{ route('news.show', $contentItem->alias) }}">
                        <img src="{{ $cover->publicUrl() }}" alt="{{ $contentItem->title }}" loading="lazy" class="app-content-page__cover">
                    </a>
                @endif
                <div class="app-content-page__card-body">
                    <p class="muted">
                        {{ $contentItem->type->label() }}
                        <time datetime="{{ $contentItem->feed_published_at->toIso8601String() }}">
                            {{ $contentItem->feed_published_at->locale('ru')->translatedFormat('j F Y') }}
                        </time>
                    </p>
                    <h2><a href="{{ route('news.show', $contentItem->alias) }}">{{ $contentItem->title }}</a></h2>
                    <p>{{ $contentItem->short_description }}</p>
                    <a class="button secondary" href="{{ route('news.show', $contentItem->alias) }}">Читать материал</a>
                    @if(isset($reactionSummaries[$contentItem->id]))
                        @includeWhen(view()->exists('theme::partials.reaction-vote'), 'theme::partials.reaction-vote', [
                            'summary' => $reactionSummaries[$contentItem->id],
                            'subjectType' => 'content',
                            'subjectId' => $contentItem->id,
                            'compact' => true,
                        ])
                    @endif
                </div>
            </article>
        @empty
            <div class="panel app-content-page__empty">
                <h2>Материалов пока нет</h2>
                <p>Опубликованные новости появятся здесь.</p>
            </div>
        @endforelse
    </div>
    @if($contentItems->hasPages())
        <nav class="app-content-page__pagination" aria-label="Страницы новостей">{{ $contentItems->links() }}</nav>
    @endif
</section>
@endsection
