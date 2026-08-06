@php
    $organizer = $event->organizerActor?->user;
    $organizerName = trim(implode(' ', array_filter([$organizer?->profile?->first_name, $organizer?->profile?->last_name]))) ?: $organizer?->username ?: 'Не указан';
    $participantName = static fn ($participant): string => trim(implode(' ', array_filter([$participant->user?->profile?->first_name, $participant->user?->profile?->last_name]))) ?: $participant->user?->username ?: 'Пользователь #'.$participant->user_id;
@endphp

@extends('theme::layouts.section-sidebar', [
    'title' => 'Мероприятие · '.$event->title,
    'sectionId' => 'admin',
    'sectionClass' => 'admin-section',
    'contentTitle' => $event->title,
    'contentSubtitle' => 'Административный контекст',
])

@section('section-sidebar')
<div class="section-sidebar-block"><h2 class="section-sidebar-block__title">Администрирование</h2><ul class="sidebar-nav nav flex-column">
<li class="nav-item"><a class="nav-link" href="{{ route('admin.events') }}">Все мероприятия</a></li>
<li class="nav-item active"><a class="nav-link active" href="{{ route('admin.events.show', $event->routeIdentifier()) }}">Карточка мероприятия</a></li>
</ul></div>
@endsection

@section('section-content')
<section class="section-card mb-4">
    <div class="d-flex flex-wrap justify-content-between gap-3 align-items-start">
        <div><span class="eyebrow">Системные сведения</span><h2>{{ $event->title }}</h2><p>{{ $event->description ?: 'Описание не заполнено.' }}</p></div>
        @unless($event->trashed())<a class="btn btn--secondary btn--sm" href="{{ route('events.show', $event->routeIdentifier()) }}" target="_blank" rel="noopener">Посмотреть на сайте</a>@endunless
    </div>
    <dl class="venue-side-meta mt-3">
        <div><dt>ID</dt><dd>{{ $event->id }}</dd></div>
        <div><dt>Тип</dt><dd>{{ $event->type->label() }}</dd></div>
        <div><dt>Статус</dt><dd>{{ $event->status->label() }}{{ $event->trashed() ? ' · удалено' : '' }}</dd></div>
        <div><dt>Видимость</dt><dd>{{ $event->visibility->label() }}</dd></div>
        <div><dt>Организатор</dt><dd>{{ $organizerName }}</dd></div>
        <div><dt>Площадка</dt><dd>{{ $event->venue?->name ?: 'Не указана' }}</dd></div>
        <div><dt>Время</dt><dd>{{ $event->starts_at?->format('d.m.Y H:i') }}–{{ $event->ends_at?->format('d.m.Y H:i') }}</dd></div>
        <div><dt>Бронирование</dt><dd>{{ $event->booking?->status?->label() ?: 'Нет' }}</dd></div>
    </dl>
</section>

@if($event->games->isNotEmpty())
<section class="section-card mb-4">
    <h2>Игры мероприятия</h2>
    @foreach($event->games as $game)
        <article class="section-card mb-2">
            <strong>{{ $game->title ?: 'Игра #'.$game->id }}</strong>
            <p>{{ $game->formatLabel() }} · {{ $game->scoring_type->label() }}</p>
            <p class="form-hint">Стороны: {{ $game->sides->map(fn($side) => $side->display_name.' — '.($side->score ?? '—'))->join(' / ') ?: 'не сформированы' }}</p>
            <a class="btn btn--secondary btn--sm" href="{{ route('events.games.show', [$event->routeIdentifier(), $game->id]) }}">Открыть игру</a>
        </article>
    @endforeach
</section>
@endif

<section class="section-card">
    <h2>Участники и ответственности</h2>
    @forelse($event->participants as $participant)
        <article class="section-card mb-3">
            <strong>{{ $participantName($participant) }}</strong>
            <p class="form-hint">Участие: {{ $participant->status->label() }} · роль: {{ $participant->role->label() }}</p>
            <p class="form-hint">Ответственность: {{ $participant->responsibility_status?->label() ?: 'нет' }} · права: {{ $participant->responsibilityPermissions->pluck('permission')->join(', ') ?: 'нет' }}</p>
        </article>
    @empty<p class="form-hint">Участников нет.</p>@endforelse
</section>
@endsection
