@php
    $title = $contentItem->title;
    $cover = $contentItem->cover->first();
    $contentHtml = \Illuminate\Support\Str::markdown($contentItem->full_description, [
        'html_input' => 'strip',
        'allow_unsafe_links' => false,
    ]);
@endphp

@extends('theme::layouts.app', ['title' => $title])

@section('content')
    <div class="inner news-article-shell">
        <nav class="breadcrumbs" aria-label="Хлебные крошки">
            <a href="{{ route('welcome') }}">Главная</a>
            <span aria-hidden="true">›</span>
            <a href="{{ route('news.index') }}">Новости</a>
            <span aria-hidden="true">›</span>
            <span>{{ $contentItem->title }}</span>
        </nav>

        <article class="news-article">
            <header class="news-article__header">
                <div class="news-card__meta">
                    <span class="badge">{{ $contentItem->type->label() }}</span>
                    <time datetime="{{ $contentItem->feed_published_at->toIso8601String() }}">{{ $contentItem->feed_published_at->translatedFormat('d F Y') }}</time>
                </div>
                <h1>{{ $contentItem->title }}</h1>
                <p class="news-article__lead">{{ $contentItem->short_description }}</p>
            </header>

            @if($cover)
                <img class="news-article__cover" src="{{ $cover->publicUrl() }}" alt="">
            @endif

            <div class="news-article__content">{!! $contentHtml !!}</div>

            @if($contentItem->link_url)
                <a class="btn btn--primary news-article__action" href="{{ $contentItem->destinationUrl() }}">Перейти</a>
            @endif
        </article>
    </div>
@endsection
