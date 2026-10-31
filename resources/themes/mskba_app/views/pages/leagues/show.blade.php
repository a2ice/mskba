@extends('theme::layouts.app', ['title' => $league->name])

@section('content')
<section class="container app-league-section" aria-labelledby="league-heading">
    <nav class="app-league-breadcrumbs" aria-label="Навигация по лиге"><a href="{{ route('leagues.index') }}">Лиги</a><span aria-hidden="true">/</span><span>{{ $league->name }}</span></nav>
    @if(method_exists($league, 'bannerUrl') && view()->exists('components.entity-public-banner'))
        @include('components.entity-public-banner', ['bannerUrl' => $league->bannerUrl()])
    @endif
    @if(auth()->user()?->canonical()->id === $league->owner_user_id && \Illuminate\Support\Facades\Route::has('account.leagues.banner'))
        <p class="app-league-owner-actions"><a class="button secondary" href="{{ route('account.leagues.banner', $league->id) }}">Управлять баннером лиги и сезонов</a></p>
    @endif
    <header class="panel app-league-hero">
        @if ($league->logo_path)
            <img class="app-league-logo app-league-logo--large" src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($league->logo_path) }}" alt="">
        @else
            <span class="app-league-logo app-league-logo--large app-league-logo--empty" aria-hidden="true">🏀</span>
        @endif
        <div>
            <span class="badge">{{ $league->status === 'active' ? 'Действующая лига' : 'Архив лиги' }}</span>
            <h1 id="league-heading">{{ $league->name }}</h1>
            @if ($league->description)<p class="app-league-description">{{ $league->description }}</p>@endif
        </div>
    </header>

    <div class="app-league-detail-grid">
        <section class="panel app-league-block" aria-labelledby="league-seasons-heading">
            <h2 id="league-seasons-heading">Сезоны</h2>
            @forelse ($league->seasons as $season)
                <a class="app-league-item" href="{{ route('leagues.seasons.show', [$league->alias, $season->id]) }}">
                    <span><strong>{{ $season->name }}</strong>
                        <small>
                            {{ $season->starts_on?->format('d.m.Y') ?: 'Начало не указано' }}
                            @if ($season->ends_on) — {{ $season->ends_on->format('d.m.Y') }} @endif
                            · Команд: {{ $season->teams_count }}
                        </small>
                    </span>
                    <span class="badge">{{ $season->status === 'active' ? 'Активный' : 'Архив' }}</span>
                </a>
            @empty
                <p class="app-team-card__detail">Опубликованных сезонов пока нет.</p>
            @endforelse
        </section>

        <section class="panel app-league-block" aria-labelledby="league-teams-heading">
            <h2 id="league-teams-heading">Команды</h2>
            @forelse ($league->teams as $team)
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

        <section class="panel app-league-block app-league-block--wide" aria-labelledby="league-tournaments-heading">
            <h2 id="league-tournaments-heading">Турниры</h2>
            @forelse ($league->tournaments as $tournament)
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
