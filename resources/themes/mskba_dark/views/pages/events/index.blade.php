@php $title = 'Мероприятия'; @endphp

@extends('theme::layouts.section-sidebar', [
    'title' => $title,
    'sectionId' => 'events',
    'sectionClass' => 'events-section',
    'contentTitle' => $period === 'past' ? 'Прошедшие мероприятия' : 'Предстоящие мероприятия',
    'contentSubtitle' => 'Игры и тренировки на баскетбольных площадках.',
    'sidebarLabel' => 'Навигация мероприятий',
])

@section('section-heading-action')
    @php
        $createLabel = $selectedType?->createLabel() ?? 'Создать мероприятие';
        $createUrl = route('events.create', array_filter(['type' => $selectedType?->value]));
    @endphp
    @auth
        <a href="{{ $createUrl }}" class="btn btn--primary btn--sm">{{ $createLabel }}</a>
    @else
        <button type="button" class="btn btn--primary btn--sm js-handler" data-handler="modal" data-modal-action="open" data-modal-target="auth-entry-classic" data-auth-redirect-url="{{ route('events.create', array_filter(['type' => $selectedType?->value]), false) }}">{{ $createLabel }}</button>
    @endauth
@endsection

@section('section-sidebar')
    @php
        $persistentFilters = array_filter([
            'type' => $typeFilter,
            'period' => $period,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'outcome' => $outcome,
        ]);
    @endphp
    <div class="section-sidebar-block">
        <h2 class="section-sidebar-block__title">Мероприятия</h2>
        <ul class="sidebar-nav nav flex-column">
            <li class="nav-item {{ $typeFilter === null ? 'active' : '' }}"><a class="nav-link {{ $typeFilter === null ? 'active' : '' }}" href="{{ route('events.index', array_diff_key($persistentFilters, ['type' => true])) }}">Все</a></li>
            @foreach($types as $type)
                <li class="nav-item {{ $selectedType === $type ? 'active' : '' }}"><a class="nav-link {{ $selectedType === $type ? 'active' : '' }}" href="{{ route('events.index', array_merge($persistentFilters, ['type' => $type->value])) }}">{{ $type->label() }}</a></li>
            @endforeach
        </ul>
    </div>
    <div class="section-sidebar-block">
        <h2 class="section-sidebar-block__title">Период</h2>
        <ul class="sidebar-nav nav flex-column">
            <li class="nav-item {{ $period === 'upcoming' ? 'active' : '' }}"><a class="nav-link {{ $period === 'upcoming' ? 'active' : '' }}" href="{{ route('events.index', array_filter(['type' => $typeFilter, 'date_from' => $dateFrom, 'date_to' => $dateTo])) }}">Предстоящие</a></li>
            <li class="nav-item {{ $period === 'past' ? 'active' : '' }}"><a class="nav-link {{ $period === 'past' ? 'active' : '' }}" href="{{ route('events.index', array_filter(['type' => $typeFilter, 'period' => 'past', 'date_from' => $dateFrom, 'date_to' => $dateTo])) }}">Прошедшие</a></li>
        </ul>
    </div>
@endsection

@section('section-content')
    @if(session('status')) <div class="alert alert-success mb-3">{{ session('status') }}</div> @endif

    <details class="section-list-item event-filters-shell mb-4">
        <summary class="event-filters-shell__summary">
            <span>Поиск</span>
            <span class="event-filters-shell__indicator" aria-hidden="true"></span>
        </summary>

        <form method="GET" action="{{ route('events.index') }}" class="event-filters event-filters--{{ $period }}">
            <input type="hidden" name="period" value="{{ $period }}">
            <div class="event-filters__grid">
                <div class="form-group field event-filters__field--type">
                    <label class="form-label" for="eventFilterType">Тип</label>
                    <select id="eventFilterType" class="form-select" name="type">
                        <option value="">Все типы</option>
                        <option value="games" @selected($typeFilter === 'games')>Игры и игровые тренировки</option>
                        @foreach($types as $type)
                            <option value="{{ $type->value }}" @selected($selectedType === $type)>{{ $type->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group field event-filters__field--date">
                    <label class="form-label" for="eventFilterDateFrom">Дата с</label>
                    <input id="eventFilterDateFrom" type="date" class="form-control" name="date_from" value="{{ $dateFrom }}">
                </div>
                <div class="form-group field event-filters__field--date">
                    <label class="form-label" for="eventFilterDateTo">Дата по</label>
                    <input id="eventFilterDateTo" type="date" class="form-control" name="date_to" value="{{ $dateTo }}">
                </div>
                @if($period === 'past')
                    <div class="form-group field event-filters__field--outcome">
                        <label class="form-label" for="eventFilterOutcome">Итог</label>
                        <select id="eventFilterOutcome" class="form-select" name="outcome">
                            <option value="">Все итоги</option>
                            <option value="completed" @selected($outcome === 'completed')>Состоялось</option>
                            <option value="cancelled" @selected($outcome === 'cancelled')>Отменено</option>
                            <option value="unmarked" @selected($outcome === 'unmarked')>Итог не указан</option>
                        </select>
                    </div>
                @endif
                <div class="event-filters__actions">
                    <button class="btn btn--primary btn--sm" type="submit">Применить</button>
                    <a class="btn btn--secondary btn--sm" href="{{ route('events.index', $period === 'past' ? ['period' => 'past'] : []) }}">Сбросить</a>
                </div>
            </div>
            @error('date_to') <div class="invalid-feedback d-block mt-2">{{ $message }}</div> @enderror
        </form>
    </details>

    @if($events->isEmpty())
        <div class="alert alert-info">Подходящих мероприятий пока нет.</div>
    @else
        <div class="section-list">
            @foreach($events as $event)
                @php
                    $timezone = $event->venue->schedule?->timezone ?: config('app.timezone');
                    $outcome = match($event->status->value) {
                        'completed' => ['Состоялось', 'success'],
                        'cancelled' => ['Отменено', 'danger'],
                        default => ['Итог не указан', 'warning'],
                    };
                @endphp
                <article class="section-list-item">
                    <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                        <span>
                            <span class="badge">{{ $event->type->label() }}</span>
                            @if($period === 'past')
                                <span class="badge badge--{{ $outcome[1] }}">{{ $outcome[0] }}</span>
                            @else
                                <span class="badge badge--{{ $event->status->value === 'published' ? 'success' : 'warning' }}">{{ $event->status->label() }}</span>
                            @endif
                        </span>
                        <time datetime="{{ $event->starts_at->toIso8601String() }}">{{ $event->starts_at->setTimezone($timezone)->format('d.m.Y H:i') }}</time>
                    </div>
                    <h2 class="h4 mb-2"><a href="{{ route('events.show', $event->routeIdentifier()) }}">{{ $event->title }}</a></h2>
                    <p class="mb-2">{{ $event->venue->name }} · {{ $event->venue->raw_address }}</p>
                    <p class="mb-3">Участники: {{ $event->participants_count }}{{ $event->max_participants ? ' / '.$event->max_participants : '' }}</p>
                    <a class="btn btn--secondary btn--sm" href="{{ route('events.show', $event->routeIdentifier()) }}">Подробнее</a>
                </article>
            @endforeach
        </div>
        <div class="mt-4">{{ $events->links() }}</div>
    @endif
@endsection
