@php
    $title = 'Команды';
    $activeFilterCount = collect(['member_count', 'sport_type'])->filter(fn ($key) => filled($filters[$key] ?? null))->count();
@endphp

@extends('theme::layouts.app', ['title' => $title])

@section('content')
    <section class="teams-catalog first-screen" data-team-catalog>
        <div class="inner teams-catalog__inner">
            @if(session('status')) <div class="alert alert-success mb-3">{{ session('status') }}</div> @endif

            <header class="teams-catalog__header">
                <h1>{{ $title }}</h1>
                <button class="page-breadcrumbs__back teams-catalog__back js-handler" type="button" data-handler="historyBack">
                    <i class="ti ti-arrow-left" aria-hidden="true"></i><span>Назад</span>
                </button>
            </header>

            <div class="catalog-toolbar teams-catalog-toolbar is-filters-collapsed">
                <label class="catalog-toolbar__search" aria-label="Поиск команд"><i class="ti ti-search" aria-hidden="true"></i><input type="search" name="q" value="{{ $filters['q'] }}" placeholder="Название или описание" form="team-catalog-filter-form"></label>
                <button class="btn btn--secondary catalog-toolbar__filter-button teams-catalog__filters-toggle" type="button" data-team-filter-toggle aria-label="Расширенные фильтры команд" aria-expanded="false">
                    <i class="ti ti-adjustments-horizontal" aria-hidden="true"></i><span class="catalog-toolbar__button-text">Фильтры</span><i class="ti ti-chevron-down catalog-toolbar__chevron" data-team-filter-toggle-icon aria-hidden="true"></i>
                    @if($activeFilterCount > 0)<b>{{ $activeFilterCount }}</b>@endif
                </button>
                @auth
                    @can('team-create')<a class="btn btn--primary" href="{{ route('teams.create') }}" aria-label="Создать команду" title="Создать команду" data-tooltip-variant="title" data-tooltip-icon><i class="ti ti-plus"></i><span class="catalog-toolbar__button-text">Создать</span></a>@endcan
                @else
                    <button type="button" class="btn btn--primary js-handler" aria-label="Создать команду" title="Создать команду" data-tooltip-variant="title" data-tooltip-icon data-handler="modal" data-modal-action="open" data-modal-target="auth-entry-classic" data-auth-redirect-url="{{ route('teams.create', absolute: false) }}"><i class="ti ti-plus"></i><span class="catalog-toolbar__button-text">Создать</span></button>
                @endauth
            </div>

            <form id="team-catalog-filter-form" method="GET" action="{{ route('teams.index') }}" class="catalog-toolbar__filters teams-catalog-filters" data-team-filters hidden>
                <label>
                    <span>Размер состава</span>
                    <select name="member_count" class="form-select">
                        <option value="">Любой</option>
                        <option value="small" @selected($filters['member_count'] === 'small')>До 5 участников</option>
                        <option value="medium" @selected($filters['member_count'] === 'medium')>6–10 участников</option>
                        <option value="large" @selected($filters['member_count'] === 'large')>11 и более</option>
                    </select>
                </label>
                <label>
                    <span>Тип команды</span>
                    <select name="sport_type" class="form-select">
                        <option value="">Любая</option>
                        <option value="streetball" @selected($filters['sport_type'] === 'streetball')>Стритбольная</option>
                        <option value="basketball" @selected($filters['sport_type'] === 'basketball')>Баскетбольная</option>
                    </select>
                </label>
                <div class="teams-catalog-filters__actions">
                    <button class="btn btn--primary" type="submit">Применить</button>
                    <a class="btn btn--secondary" href="{{ route('teams.index') }}">Сбросить</a>
                </div>
            </form>

            <div class="teams-catalog-results">
                @forelse($teams as $team)
                    @php
                        $coach = $team->memberships->first(fn ($membership) => $membership->hasSportRole(\App\Modules\Team\Domain\Enums\TeamMemberTypeEnum::COACH));
                        $captain = $team->memberships->first(fn ($membership) => $membership->is_captain);
                        $memberName = function ($membership): string {
                            if ($membership === null) return '—';
                            $profile = $membership->user->profile;
                            return trim(implode(' ', array_filter([$profile?->first_name, $profile?->last_name])))
                                ?: $membership->user->username
                                ?: '—';
                        };
                        $activePlayerIds = $team->memberships
                            ->filter(fn ($membership) => $membership->hasSportRole(\App\Modules\Team\Domain\Enums\TeamMemberTypeEnum::PLAYER))
                            ->pluck('id');
                        $rosterComplete = $team->sportProfiles->every(function ($profile) use ($activePlayerIds): bool {
                            $required = $profile->sport_type === \App\Modules\Team\Domain\Enums\TeamSportTypeEnum::STREETBALL ? 3 : 5;
                            return $profile->lineupMembers
                                ->where('assignment', \App\Modules\Team\Domain\Enums\TeamLineupAssignmentEnum::STARTER)
                                ->whereIn('contract_membership_id', $activePlayerIds)->count() === $required;
                        });
                        $teamStatusIcon = match ($team->status) {
                            \App\Modules\Team\Domain\Enums\TeamStatusEnum::ACTIVE => 'ti-circle-check',
                            \App\Modules\Team\Domain\Enums\TeamStatusEnum::DRAFT => 'ti-pencil',
                            \App\Modules\Team\Domain\Enums\TeamStatusEnum::BLOCKED => 'ti-lock',
                            \App\Modules\Team\Domain\Enums\TeamStatusEnum::ARCHIVED => 'ti-archive',
                        };
                    @endphp
                    <article class="catalog-card team-catalog-card">
                        <a class="catalog-card__image team-catalog-card__image" href="{{ route('teams.show', $team->routeIdentifier()) }}">
                            <img src="{{ $team->logo?->publicUrl() ?: asset('images/team-placeholder.webp') }}" alt="Логотип команды {{ $team->name }}">
                        </a>
                        <div class="catalog-card__body team-catalog-card__body">
                            <div class="catalog-card__badges team-catalog-card__badges">
                                <span class="catalog-card__badge team-status-badge" title="{{ $team->status->label() }}" data-tooltip-variant="title" data-tooltip-icon aria-label="{{ $team->status->label() }}">
                                    <span class="team-status-badge__label">{{ $team->status->label() }}</span>
                                    <i class="ti {{ $teamStatusIcon }} team-status-badge__icon" aria-hidden="true"></i>
                                </span>
                                @foreach($team->sportProfiles as $profile)
                                    <span class="catalog-card__badge is-sport" title="{{ $profile->sport_type->label() }}" data-tooltip-variant="title" data-tooltip-icon aria-label="{{ $profile->sport_type->label() }}">
                                        <span class="is-sport__full" aria-hidden="true">{{ $profile->sport_type->label() }}</span>
                                        <span class="is-sport__short" aria-hidden="true">{{ $profile->sport_type->shortLabel() }}</span>
                                    </span>
                                @endforeach
                                @unless($rosterComplete)
                                    <span class="catalog-card__badge is-incomplete team-status-badge" title="Неполный состав" data-tooltip-variant="title" data-tooltip-icon aria-label="Неполный состав">
                                        <span class="team-status-badge__label">Неполный состав</span>
                                        <i class="ti ti-alert-triangle team-status-badge__icon" aria-hidden="true"></i>
                                    </span>
                                @endunless
                            </div>
                            <h2 class="catalog-card__title"><a href="{{ route('teams.show', $team->routeIdentifier()) }}">{{ $team->name }}</a></h2>
                            <p class="catalog-card__description team-catalog-card__description">{{ $team->description ?: 'Описание команды пока не добавлено.' }}</p>
                            <div class="team-catalog-card__meta">
                                <p class="team-catalog-card__members" aria-label="{{ $team->active_memberships_count }} участников"><i class="ti ti-users"></i><span class="team-catalog-card__member-count">{{ $team->active_memberships_count }}</span><span class="team-catalog-card__member-label"> участников</span></p>
                                <p class="team-catalog-card__members team-catalog-card__coach @if($coach === null) is-missing @endif" aria-label="Тренер: {{ $memberName($coach) }}"><i class="ti ti-user-cog"></i><span>Тренер: {{ $memberName($coach) }}</span></p>
                                <p class="team-catalog-card__members team-catalog-card__captain @if($captain === null) is-missing @endif" aria-label="Капитан: {{ $memberName($captain) }}"><i class="ti ti-star"></i><span>Капитан: {{ $memberName($captain) }}</span></p>
                            </div>
                        </div>
                        <div class="catalog-card__actions team-catalog-card__actions">
                            <a class="btn btn--secondary btn--sm" href="{{ route('teams.show', $team->routeIdentifier()) }}">Подробнее<i class="ti ti-arrow-right"></i></a>
                        </div>
                    </article>
                @empty
                    <div class="teams-catalog__empty"><i class="ti ti-users-off"></i><strong>Команды не найдены</strong><span>Попробуйте изменить условия поиска</span><a class="btn btn--secondary btn--sm" href="{{ route('teams.index') }}">Сбросить параметры</a></div>
                @endforelse
            </div>

            <div class="teams-catalog__pagination">{{ $teams->links() }}</div>
        </div>
    </section>
@endsection
