@php
    $title = 'FAQ';
    $metaTitle = 'FAQ — Помощь и инструкции MSKBA';
    $metaDescription = 'Ответы на вопросы о регистрации, площадках, командах, тренировках и мероприятиях MSKBA.';
@endphp
@extends('theme::layouts.app', ['title' => $title])

@section('content')
<section class="container app-content-page" aria-labelledby="faq-heading">
    <header class="app-account-overview__intro">
        <h1 id="faq-heading">FAQ</h1>
        <p>Ответы на частые вопросы и инструкции по работе с MSKBA.</p>
    </header>
    <div class="app-content-page__grid">
        @forelse($contentItems as $contentItem)
            <article class="panel app-content-page__card">
                <h2><a href="{{ $contentItem->publicUrl() }}">{{ $contentItem->title }}</a></h2>
                <p>{{ $contentItem->short_description }}</p>
                @if($contentItem->tags->isNotEmpty())
                    <div class="app-content-page__tags">
                        @foreach($contentItem->tags as $tag)<span class="badge">{{ $tag->name }}</span>@endforeach
                    </div>
                @endif
                <a class="button secondary" href="{{ $contentItem->publicUrl() }}">Открыть</a>
            </article>
        @empty
            @foreach(config('creation-guides') as $topic => $guide)
                <article class="panel app-content-page__card">
                    <h2><a href="{{ route('faq.creation', ['topic' => $topic]) }}">{{ $guide['heading'] }}</a></h2>
                    <p>{{ $guide['intro'] }}</p>
                    <a class="button secondary" href="{{ route('faq.creation', ['topic' => $topic]) }}">Открыть</a>
                </article>
            @endforeach
            <article class="panel app-content-page__card">
                <h2><a href="{{ route('faq.welcome') }}">Первые шаги</a></h2>
                <p>Подтверждение контактов и аккаунта, настройка ролей и прав.</p>
                <a class="button secondary" href="{{ route('faq.welcome') }}">Открыть</a>
            </article>
        @endforelse
    </div>
</section>
@endsection
