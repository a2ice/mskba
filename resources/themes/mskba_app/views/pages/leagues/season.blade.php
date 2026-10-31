@extends('theme::layouts.app', ['title' => $season->name.' — '.$league->name])

@section('content')
<section class="container app-league-section" aria-labelledby="league-season-heading">
    <nav class="app-league-breadcrumbs" aria-label="Навигация по сезону">
        <a href="{{ route('leagues.index') }}">Лиги</a><span aria-hidden="true">/</span>
        <a href="{{ route('leagues.show', $league->alias) }}">{{ $league->name }}</a><span aria-hidden="true">/</span>
        <span>{{ $season->name }}</span>
    </nav>
    <header class="panel app-league-hero">
        @if ($league->logo_path)
            <img class="app-league-logo app-league-logo--large" src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($league->logo_path) }}" alt="">
        @else
            <span class="app-league-logo app-league-logo--large app-league-logo--empty" aria-hidden="true">🏀</span>
        @endif
        <div>
            <span class="badge">{{ $season->status === 'active' ? 'Активный сезон' : 'Архивный сезон' }}</span>
            <h1 id="league-season-heading">{{ $season->name }}</h1>
            <p class="app-team-card__detail">Лига: <a href="{{ route('leagues.show', $league->alias) }}">{{ $league->name }}</a></p>
            @if ($season->starts_on || $season->ends_on)
                <p class="app-team-card__detail">
                    {{ $season->starts_on?->format('d.m.Y') ?: 'Начало не указано' }}
                    — {{ $season->ends_on?->format('d.m.Y') ?: 'без даты окончания' }}
                </p>
            @endif
            @if ($season->description)<p class="app-league-description">{{ $season->description }}</p>@endif
        </div>
    </header>
    <div class="app-league-detail-grid">
        <section class="panel app-league-block" aria-labelledby="season-teams-heading">
            <h2 id="season-teams-heading">Команды сезона</h2>
            @forelse ($season->teams as $team)
                <a class="app-league-item" href="{{ route('teams.show', $team->routeIdentifier()) }}">
                    <span class="app-league-team">
                        @if ($team->logo?->publicUrl())<img src="{{ $team->logo->publicUrl() }}" alt="" loading="lazy">@endif
                        <strong>{{ $team->name }}</strong>
                    </span>
                </a>
            @empty
                <p class="app-team-card__detail">Команды пока не добавлены.</p>
            @endforelse
        </section>
        <section class="panel app-league-block" aria-labelledby="season-tournaments-heading">
            <h2 id="season-tournaments-heading">Турниры сезона</h2>
            @forelse ($season->tournaments as $tournament)
                <a class="app-league-item" href="{{ route('tournaments.show', $tournament->routeIdentifier()) }}">
                    <span><strong>{{ $tournament->title }}</strong>
                        <small>{{ $tournament->starts_on?->format('d.m.Y') ?: 'Дата уточняется' }}</small>
                    </span>
                </a>
            @empty
                <p class="app-team-card__detail">Опубликованных турниров пока нет.</p>
            @endforelse
        </section>
    </div>
</section>
@endsection
