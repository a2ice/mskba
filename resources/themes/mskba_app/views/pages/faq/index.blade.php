@php
    $title = 'FAQ';
    $faqItems = $contentItems ?? collect();
@endphp
@extends('theme::layouts.app', ['title' => $title])

@section('content')
    <section class="container app-team-section app-team-public" aria-labelledby="faq-heading">
        <header class="app-account-overview__intro">
            <h1 id="faq-heading">FAQ</h1>
            <p>Ответы на частые вопросы и короткие инструкции по работе с MSKBA.</p>
        </header>

        <div class="app-team-grid" aria-label="Вопросы и инструкции">
            @forelse($faqItems as $contentItem)
                <article class="panel app-team-card">
                    <div class="app-team-card__title">
                        <h2><a href="{{ $contentItem->publicUrl() }}">{{ $contentItem->title }}</a></h2>
                    </div>
                    <p class="app-team-card__detail">{{ $contentItem->short_description }}</p>
                    <div class="app-team-card__sports">
                        @foreach($contentItem->tags as $tag)
                            <span class="badge">{{ $tag->name }}</span>
                        @endforeach
                    </div>
                    <a class="button secondary" href="{{ $contentItem->publicUrl() }}">Открыть</a>
                </article>
            @empty
                @foreach(config('creation-guides', []) as $topic => $guide)
                    <article class="panel app-team-card">
                        <div class="app-team-card__title">
                            <h2><a href="{{ route('faq.creation', ['topic' => $topic]) }}">{{ $guide['heading'] }}</a></h2>
                        </div>
                        <p class="app-team-card__detail">{{ $guide['intro'] }}</p>
                        <a class="button secondary" href="{{ route('faq.creation', ['topic' => $topic]) }}">Открыть</a>
                    </article>
                @endforeach
                <article class="panel app-team-card">
                    <div class="app-team-card__title">
                        <h2><a href="{{ route('faq.welcome') }}">Первые шаги</a></h2>
                    </div>
                    <p class="app-team-card__detail">Подтверди контакт, заполни профиль и настрой личный кабинет.</p>
                    <a class="button secondary" href="{{ route('faq.welcome') }}">Открыть</a>
                </article>
            @endforelse
        </div>
    </section>
@endsection
