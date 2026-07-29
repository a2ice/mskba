@php
    $title = $contentItem->title;
    $cover = $contentItem->cover->first();
    $contentHtml = \Illuminate\Support\Str::markdown($contentItem->full_description, [
        'html_input' => 'strip',
        'allow_unsafe_links' => false,
    ]);
    $breadcrumbs = [
        ['label' => 'Новости', 'url' => route('news.index')],
        ['label' => $contentItem->title],
    ];
@endphp

@extends('theme::layouts.section-sidebar', [
    'title' => $title,
    'sectionId' => 'news',
    'sectionClass' => 'news-section news-article-section',
    'contentTitle' => $contentItem->title,
    'sidebarLabel' => 'Навигация новостей',
])

@section('section-sidebar')
    <div class="section-sidebar-block">
        <h2 class="section-sidebar-block__title">Новости</h2>
        <ul class="sidebar-nav nav flex-column">
            <li class="nav-item"><a class="nav-link" href="{{ route('news.index') }}">Все материалы</a></li>
            <li class="nav-item active"><span class="nav-link active" aria-current="page">{{ $contentItem->title }}</span></li>
        </ul>
    </div>
@endsection

@section('section-content')
    <article class="news-article">
        <header class="news-article__header">
            <div class="news-card__meta">
                <span class="badge badge--primary fs-smaller">{{ $contentItem->type->label() }}</span>
                <time datetime="{{ $contentItem->feed_published_at->toIso8601String() }}"><span class="fs-smaller">{{ $contentItem->feed_published_at->translatedFormat('d F Y') }}</span></time>
            </div>
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
@endsection
