@php
    $title = 'Турниры';
    $periodLabels = [
        'all' => 'Все',
        'current' => 'Текущие',
        'upcoming' => 'Предстоящие',
        'past' => 'Прошедшие',
    ];
    $persistentFilters = array_filter([
        'query' => $query,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
    ]);
@endphp

@extends('theme::layouts.section-sidebar', [
    'title' => $title,
    'sectionId' => 'tournaments',
    'sectionClass' => 'tournaments-section',
    'contentTitle' => $periodLabels[$period].' турниры',
    'contentSubtitle' => 'Соревнования и серии баскетбольных игр.',
    'sidebarLabel' => 'Навигация турниров',
])

@section('section-sidebar')
    <div class="section-sidebar-block">
        <h2 class="section-sidebar-block__title">Турниры</h2>
        <ul class="sidebar-nav nav flex-column">
            @foreach($periodLabels as $periodValue => $periodLabel)
                @php
                    $periodQuery = $periodValue === 'all'
                        ? $persistentFilters
                        : array_merge($persistentFilters, ['period' => $periodValue]);
                    $isActivePeriod = $period === $periodValue;
                @endphp
                <li @class(['nav-item', 'active' => $isActivePeriod])>
                    <a
                        @class(['nav-link', 'active' => $isActivePeriod])
                        href="{{ route('tournaments.index', $periodQuery) }}"
                    >{{ $periodLabel }}</a>
                </li>
            @endforeach
        </ul>
    </div>
@endsection

@section('section-content')
    <div class="d-flex justify-content-end mb-3">@if(auth()->user()?->status === \App\Modules\Identity\Domain\Enums\UserStatusEnum::CONFIRMED)<a class="btn btn--primary" href="{{ route('tournaments.create') }}">Создать турнир</a>@endif</div>
    <details class="section-list-item event-filters-shell mb-4">
        <summary class="event-filters-shell__summary">
            <span>Поиск</span>
            <span class="event-filters-shell__indicator" aria-hidden="true"></span>
        </summary>

        <form method="GET" action="{{ route('tournaments.index') }}" class="event-filters tournament-filters">
            @if($period !== 'all')
                <input type="hidden" name="period" value="{{ $period }}">
            @endif
            <div class="event-filters__grid tournament-filters__grid">
                <div class="form-group field event-filters__field--type">
                    <label class="form-label" for="tournamentFilterQuery">Поиск по названию</label>
                    <input
                        id="tournamentFilterQuery"
                        type="search"
                        class="form-control"
                        name="query"
                        value="{{ $query }}"
                        maxlength="120"
                        placeholder="Введите название турнира"
                    >
                </div>
                <div class="form-group field event-filters__field--date">
                    <label class="form-label" for="tournamentFilterDateFrom">Дата с</label>
                    <input id="tournamentFilterDateFrom" type="date" class="form-control" name="date_from" value="{{ $dateFrom }}">
                </div>
                <div class="form-group field event-filters__field--date">
                    <label class="form-label" for="tournamentFilterDateTo">Дата по</label>
                    <input id="tournamentFilterDateTo" type="date" class="form-control" name="date_to" value="{{ $dateTo }}">
                </div>
                <div class="event-filters__actions">
                    <button class="btn btn--primary btn--sm" type="submit">Применить</button>
                    <a class="btn btn--secondary btn--sm" href="{{ route('tournaments.index', $period === 'all' ? [] : ['period' => $period]) }}">Сбросить</a>
                </div>
            </div>
            @error('date_to') <div class="invalid-feedback d-block mt-2">{{ $message }}</div> @enderror
        </form>
    </details>

    @forelse($tournaments as $tournament)
        <article class="section-list-item mb-3">
            <span class="eyebrow">{{ $tournament->status->label() }}</span>
            <h2><a href="{{ route('tournaments.show', $tournament->routeIdentifier()) }}">{{ $tournament->title }}</a></h2>
            <p>{{ $tournament->starts_on->format('d.m.Y') }}@if($tournament->ends_on) — {{ $tournament->ends_on->format('d.m.Y') }}@endif</p>
            @if($tournament->short_description)<p>{{ $tournament->short_description }}</p>@endif
            <br>
            <a class="btn btn--secondary btn--sm" href="{{ route('tournaments.show', $tournament->routeIdentifier()) }}">Подробнее</a>
        </article>
    @empty
        <div class="alert alert-info">Турниры по выбранным условиям не найдены.</div>
    @endforelse
    {{ $tournaments->links('theme::partials.pagination') }}
@endsection
