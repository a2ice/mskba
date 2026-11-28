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
        '@type' => $contentItem->type === \App\Modules\Content\Domain\Enums\ContentTypeEnum::MATERIAL ? 'Article' : 'NewsArticle',
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
    $types = \App\Modules\Content\Domain\Enums\ContentTypeEnum::cases();
    $filterTypes = array_values(array_filter($types, fn ($type) => $type !== \App\Modules\Content\Domain\Enums\ContentTypeEnum::SYSTEM_UPDATE));
    $filters = ['q' => '', 'type' => $contentItem->type->value];
    $categoryView = 'list';
    $categoryResetUrl = route('news.index');
    $articleSidebar = true;
    $breadcrumbs = [
        ['label' => 'Новости', 'url' => route('news.index')],
        ['label' => $contentItem->title],
    ];
@endphp
@extends('theme::layouts.app', ['title' => $title, 'breadcrumbs' => $breadcrumbs])

@section('content')
<section class="container app-category app-news-detail" id="news-article" aria-labelledby="feed-article-heading">
    <div class="app-category__layout">
        <aside class="app-category__sidebar panel" aria-label="Навигация и фильтры новостей">
            @include('theme::pages.news.partials.sidebar', ['scope' => 'desktop'])
        </aside>
        <div class="app-category__main app-news-detail__main">
            @include('theme::pages.news.partials.mobile-sidebar')
            <article class="panel app-content-page__article">
        <header class="app-content-page__article-heading">
            <h1 id="feed-article-heading">{{ $contentItem->title }}</h1>
            @include('theme::pages.news.partials.metadata')
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
        </div>
    </div>
</section>
@endsection
