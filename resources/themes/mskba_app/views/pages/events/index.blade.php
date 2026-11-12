@php
    $title = 'Мероприятия';
    $metaDescription = 'Баскетбольные игры, тренировки и спортивные мероприятия Москвы: выбирай подходящие события на MSKBA.';
    $canonicalUrl = $events->currentPage() > 1
        ? route('events.index', ['page' => $events->currentPage()])
        : route('events.index');
    $paginationPrevUrl = $events->previousPageUrl();
    $paginationNextUrl = $events->nextPageUrl();
@endphp
@extends('theme::layouts.app', ['title' => $title])

@section('content')
    <section class="container app-team-section app-team-public" aria-labelledby="events-heading">
        <header class="app-account-overview__intro">
            <h1 id="events-heading">Мероприятия</h1>
            <p>Найди баскетбольную игру, тренировку или турнир рядом с собой.</p>
        </header>

        <div class="app-team-actions">
            <a class="button secondary" href="{{ route('events.create') }}">Создать мероприятие</a>
        </div>

        <form class="panel app-team-filters" method="GET" action="{{ route('events.index') }}" aria-label="Фильтры мероприятий">
            <label class="app-team-field">
                <span>Поиск</span>
                <input type="search" name="q" value="{{ $search }}" placeholder="Название, описание или площадка">
            </label>
            <label class="app-team-field">
                <span>Период</span>
                <select name="period">
                    <option value="upcoming" @selected($period === 'upcoming')>Предстоящие</option>
                    <option value="past" @selected($period === 'past')>Прошедшие</option>
                </select>
            </label>
            <label class="app-team-field">
                <span>Формат мероприятия</span>
                <select name="type">
                    <option value="">Все виды</option>
                    <option value="games" @selected($typeFilter === 'games')>Игры</option>
                    @foreach($types as $eventType)
                        <option value="{{ $eventType->value }}" @selected($typeFilter === $eventType->value)>{{ $eventType->label() }}</option>
                    @endforeach
                </select>
            </label>
            <label class="app-team-field">
                <span>Дата с</span>
                <input type="date" name="date_from" value="{{ $dateFrom }}">
            </label>
            <label class="app-team-field">
                <span>Дата по</span>
                <input type="date" name="date_to" value="{{ $dateTo }}">
            </label>
            <label class="app-team-field">
                <span>Площадка</span>
                <select name="venue_id">
                    <option value="">Все площадки</option>
                    @foreach($filterVenues as $venue)
                        <option value="{{ $venue->id }}" @selected($venueId === (int) $venue->id)>{{ $venue->name }}</option>
                    @endforeach
                </select>
            </label>
            @if($period === 'past')
                <label class="app-team-field">
                    <span>Результат</span>
                    <select name="outcome">
                        <option value="">Все</option>
                        <option value="completed" @selected($outcome === 'completed')>Завершённые</option>
                        <option value="cancelled" @selected($outcome === 'cancelled')>Отменённые</option>
                        <option value="unmarked" @selected($outcome === 'unmarked')>Без результата</option>
                    </select>
                </label>
            @endif
            <label class="app-team-field">
                <span>Статус</span>
                <select name="status">
                    <option value="not_cancelled" @selected($statusFilter === 'not_cancelled')>Кроме отменённых</option>
                    <option value="all" @selected($statusFilter === 'all')>Все</option>
                    <option value="cancelled" @selected($statusFilter === 'cancelled')>Отменённые</option>
                </select>
            </label>
            <label class="app-team-checkbox">
                <input type="checkbox" name="has_mini_games" value="1" @checked($hasMiniGames)>
                <span>С мини-играми</span>
            </label>
            <div class="app-team-filter-actions">
                <button class="button primary" type="submit">Найти</button>
                <a class="app-team-link" href="{{ route('events.index') }}">Сбросить</a>
            </div>
        </form>

        <div class="app-team-grid" aria-label="Список мероприятий">
            @forelse($events as $event)
                <article class="panel app-team-card">
                    <div class="app-team-card__sports">
                        <span class="badge">{{ $event->type->label() }}</span>
                        <span class="badge">{{ $event->status->label() }}</span>
                    </div>
                    <div class="app-team-card__title">
                        <h2><a href="{{ route('events.show', $event->routeIdentifier()) }}">{{ $event->title }}</a></h2>
                    </div>
                    @if($event->starts_at)
                        <p class="app-team-card__detail">
                            <time datetime="{{ $event->starts_at->toIso8601String() }}">{{ $event->starts_at->locale('ru')->translatedFormat('j F Y · H:i') }}</time>
                        </p>
                    @endif
                    @if($event->venue)
                        <p class="app-team-card__detail">Площадка: {{ $event->venue->name }}</p>
                    @endif
                    <a class="button secondary" href="{{ route('events.show', $event->routeIdentifier()) }}">Подробнее</a>
                </article>
            @empty
                <section class="panel app-team-empty">
                    <h2>Подходящих мероприятий пока нет</h2>
                    <p>Попробуй изменить фильтры или вернуться к списку всех мероприятий.</p>
                    <a class="button secondary" href="{{ route('events.index') }}">Сбросить фильтры</a>
                </section>
            @endforelse
        </div>

        @if($events->hasPages())
            <nav class="app-team-pagination" aria-label="Страницы мероприятий">{{ $events->withQueryString()->links() }}</nav>
        @endif
    </section>
@endsection
