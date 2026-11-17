@php
    $title = 'Мероприятия';
    $metaDescription = 'Баскетбольные игры, тренировки и спортивные мероприятия Москвы: выбирай подходящие события на MSKBA.';
    $canonicalUrl = $events->currentPage() > 1 ? route('events.index', ['page' => $events->currentPage()]) : route('events.index');
    $paginationPrevUrl = $events->previousPageUrl();
    $paginationNextUrl = $events->nextPageUrl();
    $categoryTitle = $title;
    $categoryDescription = 'Найди баскетбольную игру или тренировку рядом с собой.';
    $categoryRootId = 'events-catalog';
    $categorySidebarLabel = 'Навигация и фильтры мероприятий';
    $categoryView = request('view') === 'list' ? 'list' : 'cards';
    $categoryBaseUrl = route('events.index');
    $categoryViewQuery = request()->except(['view', 'page', 'fragment']);
    $categoryResetUrl = $categoryBaseUrl.($categoryView === 'list' ? '?view=list' : '');
    $categoryToolbarCount = 'Найдено '.$events->total().' мероприятий';
@endphp
@extends('theme::layouts.category')

@section('category-heading-action')
    <a class="button primary" href="{{ route('events.create') }}">+ Создать мероприятие</a>
@endsection

@section('category-sidebar')
    <h2>Мероприятия</h2>
    <nav class="app-category__nav" aria-label="Разделы мероприятий">
        <a href="{{ route('events.index') }}" @class(['is-active' => !filled($typeFilter)])>Все мероприятия</a>
        <a href="{{ route('events.index', ['type' => 'games']) }}" @class(['is-active' => $typeFilter === 'games'])>Игры</a>
        @foreach($types as $eventType)
            <a href="{{ route('events.index', ['type' => $eventType->value]) }}"
               @class(['is-active' => $typeFilter === $eventType->value])>{{ $eventType->label() }}</a>
        @endforeach
    </nav>
    <div class="app-category__separator" aria-hidden="true"></div>
    <h2>Фильтры</h2>
    @include('theme::pages.events.partials.category-filters', ['scope' => 'desktop'])
@endsection

@section('category-before-items')
    <details class="app-category__mobile-filters panel">
        <summary>Категории и фильтры <span aria-hidden="true">⌄</span></summary>
        <div class="app-category__mobile-filters-body">
            <h2>Мероприятия</h2>
            <nav class="app-category__nav" aria-label="Разделы мероприятий">
                <a href="{{ route('events.index') }}" @class(['is-active' => !filled($typeFilter)])>Все мероприятия</a>
                <a href="{{ route('events.index', ['type' => 'games']) }}" @class(['is-active' => $typeFilter === 'games'])>Игры</a>
                @foreach($types as $eventType)
                    <a href="{{ route('events.index', ['type' => $eventType->value]) }}"
                        @class(['is-active' => $typeFilter === $eventType->value])>{{ $eventType->label() }}</a>
                @endforeach
            </nav>
            <h2>Фильтры</h2>
            @include('theme::pages.events.partials.category-filters', ['scope' => 'mobile'])
        </div>
    </details>
@endsection

@section('category-items')
    @forelse($events as $event)
        @php
            $eventUrl = route('events.show', $event->routeIdentifier());
            $photo = $event->venue?->media?->first();
            $timezone = $event->venue?->schedule?->timezone ?: config('app.timezone', 'Europe/Moscow');
            $starts = $event->starts_at->setTimezone($timezone);
        @endphp
        <article class="panel app-category__card" data-event-id="{{ $event->id }}">
            <a class="app-category__card-cover" href="{{ $eventUrl }}" aria-label="{{ $event->title }}">
                <img loading="lazy" src="{{ $photo?->publicUrl() ?: asset('images/venue-placeholder.png') }}" alt="{{ $event->title }}">
            </a>
            <div class="app-category__card-body">
                <div class="app-category__badges">
                    <span>{{ $event->type->label() }}</span>
                    <span>{{ $event->status->label() }}</span>
                </div>
                <h2><a href="{{ $eventUrl }}">{{ $event->title }}</a></h2>
                <p><time datetime="{{ $event->starts_at->toIso8601String() }}">{{ $starts->format('d.m.Y · H:i') }}</time></p>
                @if($event->venue)
                    <p>{{ $event->venue->name }}@if($event->venue->raw_address) · {{ $event->venue->raw_address }}@endif</p>
                @endif
                <p>Участники: {{ $event->participants_count }}{{ $event->max_participants ? ' / '.$event->max_participants : '' }}</p>
                @if($event->games->isNotEmpty() && $event->type !== \App\Modules\Event\Domain\Enums\EventTypeEnum::GAME)
                    <p>Мини-игры: {{ $event->games->count() }}</p>
                @endif
                @if($event->description)<p class="app-category__description">{{ \Illuminate\Support\Str::limit($event->description, 145) }}</p>@endif
                <a class="button secondary app-category__details" href="{{ $eventUrl }}">Подробнее</a>
            </div>
        </article>
    @empty
        <section class="panel app-category__empty">
            <h2>Мероприятия не найдены</h2>
            <p>Попробуйте изменить условия поиска или сбросить параметры.</p>
            <a class="button secondary" href="{{ route('events.index') }}">Сбросить параметры</a>
        </section>
    @endforelse
@endsection

@section('category-after-items')
    @if($events->hasPages())
        <nav class="app-category__pagination" aria-label="Страницы мероприятий">{{ $events->links() }}</nav>
    @endif
@endsection
