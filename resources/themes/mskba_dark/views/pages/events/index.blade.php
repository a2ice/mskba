@php $title = 'Мероприятия'; @endphp

@extends('theme::layouts.section-sidebar', [
    'title' => $title,
    'sectionId' => 'events',
    'sectionClass' => 'events-section',
    'contentTitle' => $period === 'past' ? 'Состоявшиеся мероприятия' : 'Предстоящие мероприятия',
    'contentSubtitle' => 'Игры, тренировки и турниры на баскетбольных площадках.',
    'sidebarLabel' => 'Навигация мероприятий',
])

@section('section-heading-action')
    @auth
        <a href="{{ route('events.create') }}" class="btn btn--primary btn--sm">Создать мероприятие</a>
    @else
        <button type="button" class="btn btn--primary btn--sm js-handler" data-handler="modal" data-modal-action="open" data-modal-target="auth-entry-classic" data-auth-redirect-url="{{ route('events.create', [], false) }}">Создать мероприятие</button>
    @endauth
@endsection

@section('section-sidebar')
    <div class="section-sidebar-block">
        <h2 class="section-sidebar-block__title">Мероприятия</h2>
        <ul class="sidebar-nav nav flex-column">
            <li class="nav-item {{ $selectedType === null ? 'active' : '' }}"><a class="nav-link {{ $selectedType === null ? 'active' : '' }}" href="{{ route('events.index', ['period' => $period]) }}">Все</a></li>
            @foreach($types as $type)
                <li class="nav-item {{ $selectedType === $type ? 'active' : '' }}"><a class="nav-link {{ $selectedType === $type ? 'active' : '' }}" href="{{ route('events.index', ['type' => $type->value, 'period' => $period]) }}">{{ $type->label() }}</a></li>
            @endforeach
        </ul>
    </div>
    <div class="section-sidebar-block">
        <h2 class="section-sidebar-block__title">Период</h2>
        <ul class="sidebar-nav nav flex-column">
            <li class="nav-item {{ $period === 'upcoming' ? 'active' : '' }}"><a class="nav-link {{ $period === 'upcoming' ? 'active' : '' }}" href="{{ route('events.index', array_filter(['type' => $selectedType?->value])) }}">Предстоящие</a></li>
            <li class="nav-item {{ $period === 'past' ? 'active' : '' }}"><a class="nav-link {{ $period === 'past' ? 'active' : '' }}" href="{{ route('events.index', array_filter(['type' => $selectedType?->value, 'period' => 'past'])) }}">Состоявшиеся</a></li>
        </ul>
    </div>
@endsection

@section('section-content')
    @if(session('status')) <div class="alert alert-success mb-3">{{ session('status') }}</div> @endif

    @if($events->isEmpty())
        <div class="alert alert-info">Подходящих мероприятий пока нет.</div>
    @else
        <div class="section-list">
            @foreach($events as $event)
                @php $timezone = $event->venue->schedule?->timezone ?: config('app.timezone'); @endphp
                <article class="section-list-item">
                    <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                        <span class="badge badge--{{ $event->status->value === 'published' ? 'success' : 'warning' }}">{{ $event->type->label() }} · {{ $event->status->label() }}</span>
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
