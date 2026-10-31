@extends('theme::layouts.app', ['title' => 'Лиги'])

@section('content')
<section class="container app-league-section" aria-labelledby="leagues-heading">
    <header class="app-account-overview__intro">
        <h1 id="leagues-heading">Лиги</h1>
        <p>Баскетбольные лиги Москвы: команды, сезоны и турниры.</p>
    </header>

    <form class="panel app-league-filters" method="GET" action="{{ route('leagues.index') }}" aria-label="Поиск лиг">
        <label class="app-team-field">
            <span>Название или описание</span>
            <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="Название лиги">
        </label>
        <label class="app-team-field">
            <span>Статус</span>
            <select name="status">
                <option value="">Все</option>
                <option value="active" @selected($filters['status'] === 'active')>Действующие</option>
                <option value="archived" @selected($filters['status'] === 'archived')>Архивные</option>
            </select>
        </label>
        <label class="app-team-field">
            <span>Сезон</span>
            <select name="season">
                <option value="">Все сезоны</option>
                @foreach ($seasons as $season)
                    <option value="{{ $season->id }}" @selected((string) $filters['season'] === (string) $season->id)>{{ $season->league->name }} — {{ $season->name }}</option>
                @endforeach
            </select>
        </label>
        <div class="app-team-filter-actions">
            <button class="button primary" type="submit">Найти</button>
            <a class="app-team-link" href="{{ route('leagues.index') }}">Сбросить</a>
        </div>
    </form>

    <div class="app-league-grid" aria-label="Каталог лиг">
        @forelse ($leagues as $league)
            <article class="panel app-league-card">
                <div class="app-league-card__head">
                    @if ($league->logo_path)
                        <img class="app-league-logo" src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($league->logo_path) }}" alt="" loading="lazy">
                    @else
                        <span class="app-league-logo app-league-logo--empty" aria-hidden="true"><svg><use href="#ball"></use></svg></span>
                    @endif
                    <div>
                        <h2><a href="{{ route('leagues.show', $league->alias) }}">{{ $league->name }}</a></h2>
                        <span class="badge">{{ $league->status === 'active' ? 'Действующая' : 'В архиве' }}</span>
                    </div>
                </div>
                @if ($league->description)
                    <p class="app-league-card__description">{{ $league->description }}</p>
                @endif
                <p class="app-team-card__detail">Команд: {{ $league->teams_count }} · Сезонов: {{ $league->seasons_count }}</p>
                <a class="button secondary" href="{{ route('leagues.show', $league->alias) }}">Открыть лигу</a>
            </article>
        @empty
            <section class="panel app-team-empty">
                <h2>Лиги по этим условиям не найдены</h2>
                <p>Попробуй изменить фильтры или посмотри все лиги.</p>
                <a class="button secondary" href="{{ route('leagues.index') }}">Все лиги</a>
            </section>
        @endforelse
    </div>
    <div class="app-team-pagination">{{ $leagues->links() }}</div>
</section>
@endsection
