@php
    $headCoach = $section->headCoachMembership?->user;
    $headCoachName = trim(($headCoach?->profile?->first_name ?? '').' '.($headCoach?->profile?->last_name ?? '')) ?: ($headCoach?->username ?? 'Не указан');
    $canContact = $contacts->isNotEmpty() || filled($section->contact_notes);
@endphp
@extends('theme::layouts.app', ['title' => $section->name])

@section('content')
<section class="sports-section-show first-screen"><div class="inner">
    <header class="sports-section-show__hero">
        <div>
            <span class="text-accent">Секция · {{ $section->game_format->label() }}</span>
            <h1>{{ $section->name }}</h1>
            <p>{{ $section->description }}</p>
            @if($canContact)<div class="sports-section-show__hero-actions"><a class="btn btn--primary" href="#section-contacts">Записаться / связаться</a></div>@endif
        </div>
        @if($section->featuredMedia)<img src="{{ $section->featuredMedia->publicUrl() }}" alt="{{ $section->name }}">@endif
    </header>
    <div class="sports-section-show__facts">
        <article><h2>Главный тренер</h2><strong>{{ $headCoachName }}</strong><p>{{ $section->training_mode->label() }}</p></article>
        <article><h2>Место</h2><strong>{{ $section->primaryVenue?->name ?? 'Уточняется' }}</strong>@if($section->primaryVenueCourt)<p>{{ $section->primaryVenueCourt->name }}</p>@endif</article>
        <article><h2>Стоимость</h2><strong>{{ $section->pricing_type->label() }}</strong>@if($section->single_session_price_minor)<p>{{ number_format($section->single_session_price_minor / 100, 0, ',', ' ') }} руб. / занятие</p>@endif</article>
    </div>
    @if($section->pricingPlans->isNotEmpty())
        <section class="sports-section-show__block"><h2>Тарифы</h2><div class="sports-section-show__list">
            @foreach($section->pricingPlans as $plan)
                <article><strong>{{ $plan->name }}</strong><span>{{ number_format($plan->amount_minor / 100, 0, ',', ' ') }} руб.</span><small>{{ $plan->sessions_count ? $plan->sessions_count.' занятий' : 'Без лимита занятий' }}{{ $plan->duration_days ? ' · '.$plan->duration_days.' дней' : '' }}</small></article>
            @endforeach
        </div></section>
    @endif
    <section class="sports-section-show__block"><h2>Ближайшие занятия</h2><div class="sports-section-show__list">
        @forelse($section->trainingSessions as $session)
            <article><strong>{{ $session->starts_at->timezone(config('app.timezone'))->format('d.m.Y, H:i') }}–{{ $session->ends_at->timezone(config('app.timezone'))->format('H:i') }}</strong><span>{{ $session->venue?->name ?? 'Место уточняется' }}</span>
                @if($session->event)<a class="btn btn--secondary btn--sm" href="{{ route('events.show', $session->event) }}">Подробнее о занятии</a>@elseif($canContact)<a href="#section-contacts">Уточнить участие</a>@endif
            </article>
        @empty
            <p class="text-muted">Публичных занятий пока нет.</p>
        @endforelse
    </div></section>
    @if($canContact)
        <section class="sports-section-show__block" id="section-contacts"><h2>Запись и контакты</h2>
            <p class="text-muted">Свяжитесь с секцией или главным тренером, чтобы уточнить свободные места и ближайшее занятие.</p>
            @foreach($contacts as $contact)<p><strong>{{ $contact->type->label() }}:</strong> {{ $contact->displayValue() }}</p>@endforeach
            @if($section->contact_notes)<p class="text-muted">{{ $section->contact_notes }}</p>@endif
        </section>
    @endif
    @if($section->media->count() > 1)
        <section class="sports-section-show__block"><h2>Фотографии</h2><div class="sports-section-show__gallery">
            @foreach($section->media as $photo)<img src="{{ $photo->publicUrl() }}" alt="{{ $section->name }}">@endforeach
        </div></section>
    @endif
</div></section>
@endsection
