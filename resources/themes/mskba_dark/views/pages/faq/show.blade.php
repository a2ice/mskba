@php
    $title = $contentItem->title;
    $cover = $contentItem->cover->first();
    $contentHtml = app(\App\Modules\Content\Application\Services\ContentBodyRenderer::class)->render($contentItem);
    $metaTitle = $contentItem->meta_title ?: $contentItem->title;
    $metaDescription = $contentItem->meta_description ?: $contentItem->short_description;
    $metaKeywords = $contentItem->meta_keywords;
    $metaImage = $cover?->publicUrl();
    $canonicalUrl = $contentItem->publicUrl();
@endphp

@extends('theme::layouts.section-sidebar', [
    'title' => $title,
    'contentTitle' => $contentItem->title,
    'sectionId' => 'faq',
    'sectionClass' => 'faq-section faq-article-section',
    'sidebarLabel' => 'Навигация FAQ',
])

@section('section-sidebar')
    <div class="section-sidebar-block">
        <h2 class="section-sidebar-block__title">FAQ</h2>
        <ul class="sidebar-nav nav flex-column">
            <li class="nav-item"><a class="nav-link" href="{{ route('faq.index') }}">Все инструкции FAQ</a></li>
            <li class="nav-item active"><span class="nav-link active" aria-current="page">{{ $contentItem->title }}</span></li>
        </ul>
    </div>
@endsection

@section('section-content')
    @include('theme::pages.faq.search')

    <article class="faq-article">
        <p class="news-article__lead">{{ $contentItem->short_description }}</p>

        @if($contentItem->tags->isNotEmpty())
            <div class="faq-tags faq-tags--article">
                @foreach($contentItem->tags as $tag)
                    <span class="faq-tag">{{ $tag->name }}</span>
                @endforeach
            </div>
        @endif

        @if($cover)
            <img class="news-article__cover" src="{{ $cover->publicUrl() }}" alt="">
        @endif

        <div class="news-article__content faq-article__content">{!! $contentHtml !!}</div>

        @if($contentItem->link_url)
            <a class="btn btn--primary news-article__action" href="{{ $contentItem->destinationUrl() }}">Перейти</a>
        @endif
    </article>
@endsection
