@extends('theme::layouts.app', ['title' => 'Найти команду'])

@section('content')
    <section class="container app-team-section app-team-public" aria-labelledby="team-catalog-heading">
        <header class="app-account-overview__intro">
            <h1 id="team-catalog-heading">Найти команду</h1>
            <p>Подбери команду по формату, составу и наличию свободных мест.</p>
        </header>

        <div class="app-team-actions">
            @auth <a class="button secondary" href="{{ route('account.teams') }}">Мои команды</a> @endauth
            @can('team-create') <a class="button secondary" href="{{ route('teams.create') }}">Создать команду</a> @endcan
        </div>

        <form class="panel app-team-filters" method="GET" action="{{ route('teams.index') }}" aria-label="Поиск команд">
            <label class="app-team-field">
                <span>Название или описание</span>
                <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="Название команды">
            </label>
            <label class="app-team-field">
                <span>Формат</span>
                <select name="sport_type">
                    <option value="">Любой</option>
                    @foreach (\App\Modules\Team\Domain\Enums\TeamSportTypeEnum::cases() as $type)
                        <option value="{{ $type->value }}" @selected($filters['sport_type'] === $type->value)>{{ $type->label() }}</option>
                    @endforeach
                </select>
            </label>
            <label class="app-team-field">
                <span>Размер состава</span>
                <select name="member_count">
                    <option value="">Любой</option>
                    <option value="small" @selected($filters['member_count'] === 'small')>До 5 участников</option>
                    <option value="medium" @selected($filters['member_count'] === 'medium')>6–10 участников</option>
                    <option value="large" @selected($filters['member_count'] === 'large')>От 11 участников</option>
                </select>
            </label>
            <label class="app-team-checkbox">
                <input type="checkbox" name="hiring" value="1" @checked($filters['hiring'])>
                <span>Идёт набор</span>
            </label>
            <div class="app-team-filter-actions">
                <button class="button primary" type="submit">Найти</button>
                <a class="app-team-link" href="{{ route('teams.index') }}">Сбросить</a>
            </div>
        </form>

        <div class="app-team-grid" aria-label="Результаты поиска команд">
            @forelse ($teams as $team)
                <article class="panel app-team-card">
                    <div class="app-team-card__title">
                        <h2><a href="{{ route('teams.show', $team->routeIdentifier()) }}">{{ $team->name }}</a></h2>
                        @if ($team->active_hiring_positions_count > 0)
                            <span class="badge success">Идёт набор</span>
                        @endif
                    </div>
                    <div class="app-team-card__sports">
                        @foreach ($team->sportProfiles as $profile)
                            <span class="badge">{{ $profile->sport_type->label() }}</span>
                        @endforeach
                    </div>
                    <p class="app-team-card__detail">Участников: {{ $team->active_memberships_count }}</p>
                    <a class="button secondary" href="{{ route('teams.show', $team->routeIdentifier()) }}">Посмотреть команду</a>
                </article>
            @empty
                <section class="panel app-team-empty">
                    <h2>По твоим фильтрам команд пока нет</h2>
                    <p>Измени условия поиска или посмотри все команды.</p>
                    <a class="button secondary" href="{{ route('teams.index') }}">Все команды</a>
                </section>
            @endforelse
        </div>
        <div class="app-team-pagination">{{ $teams->links() }}</div>
    </section>
@endsection
