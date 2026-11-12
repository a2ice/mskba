@php
    $title = $contentItem->title;
    $cover = $contentItem->cover->first();
    $contentHtml = app(\App\Modules\Content\Application\Services\ContentBodyRenderer::class)->render($contentItem);
    $metaTitle = $contentItem->meta_title ?: $contentItem->title;
    $metaDescription = $contentItem->meta_description ?: $contentItem->short_description;
    $metaKeywords = $contentItem->meta_keywords;
    $metaImage = $cover?->publicUrl();
    $canonicalUrl = $contentItem->publicUrl();
    $breadcrumbs = [['label' => 'FAQ', 'url' => route('faq.index')], ['label' => $contentItem->title]];
@endphp
@extends('theme::layouts.app', ['title' => $title, 'breadcrumbs' => $breadcrumbs])

@section('content')
<section class="container app-content-page" aria-labelledby="faq-article-heading">
    <nav aria-label="Вернуться к инструкциям"><a href="{{ route('faq.index') }}">Все инструкции FAQ</a></nav>
    <article class="panel app-content-page__article">
        <h1 id="faq-article-heading">{{ $contentItem->title }}</h1>
        @if($contentItem->short_description)<p class="app-content-page__lead">{{ $contentItem->short_description }}</p>@endif
        @if($contentItem->tags->isNotEmpty())
            <div class="app-content-page__tags">
                @foreach($contentItem->tags as $tag)<span class="badge">{{ $tag->name }}</span>@endforeach
            </div>
        @endif
        @if($cover)<img class="app-content-page__cover" src="{{ $cover->publicUrl() }}" alt="{{ $contentItem->title }}">@endif
        <div class="app-content-page__body">{!! $contentHtml !!}</div>
        @if($contentItem->link_url)
            <a class="button primary" href="{{ $contentItem->destinationUrl() }}">Перейти</a>
        @endif
    </article>
</section>
@endsection
