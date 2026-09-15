@php
    $title = 'FAQ';
    $faqItems = $contentItems ?? collect();
@endphp

@extends('theme::layouts.app', ['title' => $title])

@section('content')
    <section class="faq-section first-screen">
        <div class="inner">
            <div class="mb-3">
                @include('theme::partials.breadcrumbs')
            </div>

            <div class="section-heading mb-4">
                <h1 class="section-title">{{ $title }}</h1>
                <p class="lead">Ответы на частые вопросы и короткие инструкции по работе с MSKBA.</p>
            </div>

            @include('theme::pages.faq.search')

            <div class="section-content faq-list">
                @if($faqItems->isNotEmpty())
                    @foreach($faqItems as $contentItem)
                        <article class="card mb-4"><div class="card-body">
                            <h2 class="h4 mb-3">{{ $contentItem->title }}</h2>
                            <p>{{ $contentItem->short_description }}</p>
                            @if($contentItem->tags->isNotEmpty())
                                <div class="faq-tags mb-3">
                                    @foreach($contentItem->tags as $tag)
                                        <span class="faq-tag">{{ $tag->name }}</span>
                                    @endforeach
                                </div>
                            @endif
                            <a href="{{ $contentItem->publicUrl() }}" class="btn btn--primary btn--sm">Открыть</a>
                        </div></article>
                    @endforeach
                @else
                    @foreach(config('creation-guides') as $topic => $guide)
                        <div class="card mb-4"><div class="card-body">
                            <h2 class="h4 mb-3">{{ $guide['heading'] }}</h2>
                            <p>{{ $guide['intro'] }}</p>
                            <a href="{{ route('faq.creation', ['topic' => $topic]) }}" class="btn btn--primary btn--sm">Открыть</a>
                        </div></div>
                    @endforeach
                    <div class="card mb-4">
                        <div class="card-body">
                            <h2 class="h4 mb-3">Первые шаги</h2>
                            <p class="mb-3">Что сделать после регистрации: подтвердить контакт, заполнить профиль и перейти к доступным возможностям личного кабинета.</p>
                            <a href="{{ route('faq.welcome') }}" class="btn btn--primary btn--sm">Открыть</a>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </section>
@endsection
