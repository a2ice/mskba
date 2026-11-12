@php
    $title = $contentItem->title;
    $cover = $contentItem->cover->first();
    $contentHtml = app(\App\Modules\Content\Application\Services\ContentBodyRenderer::class)->render($contentItem);
    $metaTitle = $contentItem->meta_title ?: $contentItem->title;
    $metaDescription = $contentItem->meta_description ?: $contentItem->short_description;
    $metaKeywords = $contentItem->meta_keywords;
    $metaImage = $cover?->publicUrl();
    $metaImageAlt = $contentItem->title;
    $metaType = 'article';
    $canonicalUrl = $contentItem->publicUrl();
    $structuredData = [[
        '@context' => 'https://schema.org',
        '@type' => 'NewsArticle',
        'headline' => $contentItem->title,
        'description' => $metaDescription,
        'datePublished' => $contentItem->feed_published_at?->toIso8601String(),
        'dateModified' => $contentItem->updated_at?->toIso8601String(),
        'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $canonicalUrl],
        'author' => ['@type' => 'Organization', 'name' => config('seo.site_name', 'MSKBA'), 'url' => route('welcome')],
        'publisher' => [
            '@type' => 'Organization',
            'name' => config('seo.site_name', 'MSKBA'),
            'url' => route('welcome'),
            'logo' => ['@type' => 'ImageObject', 'url' => asset((string) config('seo.organization_logo', 'images/logo.png'))],
        ],
        ...($metaImage ? ['image' => [$metaImage]] : []),
    ]];
    $breadcrumbs = [
        ['label' => 'Новости', 'url' => route('news.index')],
        ['label' => $contentItem->title],
    ];
@endphp
@extends('theme::layouts.app', ['title' => $title, 'breadcrumbs' => $breadcrumbs])

@section('content')
<section class="container app-content-page" aria-labelledby="feed-article-heading">
    <nav aria-label="Вернуться к новостям"><a href="{{ route('news.index') }}">Все новости</a></nav>
    <article class="panel app-content-page__article">
        <header class="app-content-page__article-heading">
            <p class="muted">
                {{ $contentItem->type->label() }}
                <time datetime="{{ $contentItem->feed_published_at->toIso8601String() }}">
                    {{ $contentItem->feed_published_at->locale('ru')->translatedFormat('j F Y') }}
                </time>
            </p>
            <h1 id="feed-article-heading">{{ $contentItem->title }}</h1>
            @if($contentItem->short_description)<p class="app-content-page__lead">{{ $contentItem->short_description }}</p>@endif
        </header>
        @if($cover)
            <img class="app-content-page__cover" src="{{ $cover->publicUrl() }}" alt="{{ $contentItem->title }}">
        @endif
        <div class="app-content-page__body">{!! $contentHtml !!}</div>
        @if($contentItem->link_url)
            <a class="button primary" href="{{ $contentItem->destinationUrl() }}">Перейти</a>
        @endif
    </article>
</section>
@endsection
