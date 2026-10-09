@extends('theme::layouts.account', ['title' => 'Мои команды'])

@section('account-content')
    <section class="app-team-section" aria-labelledby="account-teams-heading" data-app-team-actions>
        <header class="app-account-overview__intro">
            <h1 id="account-teams-heading">Мои команды</h1>
            <p>Твои команды, приглашения и поиск новых возможностей для игры.</p>
        </header>

        <section class="panel app-team-panel" aria-labelledby="account-my-teams-title">
            <h2 id="account-my-teams-title">Мои команды</h2>

            @if ($hasAnyTeams)
                <form class="app-team-filters" method="GET" action="{{ route('account.teams') }}" aria-label="Фильтры моих команд">
                    <label class="app-team-field">
                        <span>Статус команды</span>
                        <select name="status">
                            <option value="">Все статусы</option>
                            @foreach ($statuses as $status)
                                <option value="{{ $status->value }}" @selected($filters['status'] === $status->value)>{{ $status->label() }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="app-team-field">
                        <span>Участие</span>
                        <select name="condition">
                            <option value="">Любое участие</option>
                            @foreach (\App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum::cases() as $condition)
                                <option value="{{ $condition->value }}" @selected($filters['condition'] === $condition->value)>{{ $condition->label() }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="app-team-checkbox">
                        <input type="checkbox" name="created_only" value="1" @checked($filters['created_only'])>
                        <span>Созданные мной</span>
                    </label>
                    <div class="app-team-filter-actions">
                        <button class="button secondary" type="submit">Применить</button>
                        <a class="app-team-link" href="{{ route('account.teams', ['status' => '']) }}">Сбросить</a>
                    </div>
                </form>
            @endif

            @if (! $hasAnyTeams)
                <p class="app-team-card__detail">Найди команду по интересам и формату игры, отправь заявку на вступление или создай собственную.</p>
            @elseif ($teams->isEmpty())
                <p class="app-team-card__detail">По этим условиям команды не найдены. Попробуй изменить фильтры.</p>
                <a class="app-team-link" href="{{ route('account.teams', ['status' => '']) }}">Показать все команды</a>
            @else
                <div class="app-team-grid" aria-label="Список твоих команд">
                    @foreach ($teams as $team)
                        @php($membership = $team->memberships->first())
                        <article class="app-team-card">
                            <div class="app-team-card__top">
                                @if ($team->logo?->publicUrl())
                                    <img class="app-team-card__logo" src="{{ $team->logo->publicUrl() }}" alt="">
                                @endif
                                <div class="app-team-card__title">
                                    <h3><a href="{{ route('teams.show', $team->routeIdentifier()) }}">{{ $team->name }}</a></h3>
                                    <span class="badge">{{ $team->status->label() }}</span>
                                </div>
                            </div>
                            <div class="app-team-card__sports">
                                @foreach ($team->sportProfiles as $profile)
                                    <span class="badge">{{ $profile->sport_type->label() }}</span>
                                @endforeach
                            </div>
                            <p class="app-team-card__detail">{{ $membership?->invitation_status?->label() ?? 'Создатель команды' }}</p>
                            <a class="button secondary" href="{{ route('teams.show', $team->routeIdentifier()) }}">Открыть команду</a>
                        </article>
                    @endforeach
                </div>
                <div class="app-team-pagination">{{ $teams->links() }}</div>
            @endif

            <div class="app-team-actions app-team-panel__actions">
                <button type="button" class="button primary" data-team-placeholder-action="find">Найти команду</button>
                <button type="button" class="button secondary" data-team-placeholder-action="create">Создать команду</button>
            </div>
        </section>

        <section class="panel app-team-panel" aria-labelledby="account-team-invitations-title">
            <h2 id="account-team-invitations-title">Приглашения в команду</h2>
            @if ($invitations->isEmpty())
                <p class="app-team-card__detail">Новых приглашений нет.</p>
            @else
                <div class="app-team-grid" aria-label="Приглашения в команды">
                    @foreach ($invitations as $team)
                        @php($invitation = $team->memberships->first())
                        <article class="app-team-card">
                            <div class="app-team-card__title">
                                <h3><a href="{{ route('teams.show', $team->routeIdentifier()) }}">{{ $team->name }}</a></h3>
                                <span class="badge">{{ $invitation->invitation_status->label() }}</span>
                            </div>
                            <p class="app-team-card__detail">Тебя пригласили вступить в эту команду.</p>
                            <form method="POST" action="{{ route('teams.invitations.respond', $invitation->id) }}" class="app-team-card__footer">
                                @csrf
                                @method('PATCH')
                                <button class="button primary" type="submit" name="decision" value="accept">Принять</button>
                                <button class="button secondary" type="submit" name="decision" value="decline">Отклонить</button>
                            </form>
                        </article>
                    @endforeach
                </div>
                <div class="app-team-pagination">{{ $invitations->links() }}</div>
            @endif
        </section>

        <section class="panel app-team-panel" aria-labelledby="account-team-applications-title">
            <h2 id="account-team-applications-title">Заявки в команду</h2>
            @if ($applications->isEmpty())
                <p class="app-team-card__detail">Ты еще не подал ни одной заявки в команду.</p>
            @else
                <div class="app-team-grid" aria-label="Мои заявки на вступление">
                    @foreach ($applications as $application)
                        <article class="app-team-card">
                            <div class="app-team-card__title">
                                <h3><a href="{{ route('teams.show', $application->team->routeIdentifier()) }}">{{ $application->team->name }}</a></h3>
                                <span class="badge">{{ $application->status->label() }}</span>
                            </div>
                            @if ($application->hiringPosition)
                                <p class="app-team-card__detail">Заявка на вакансию команды</p>
                            @endif
                            @if (filled($application->review_reason) && $application->status !== \App\Modules\Team\Domain\Enums\TeamJoinRequestStatusEnum::PENDING)
                                <p class="app-team-card__detail">Ответ команды: {{ $application->review_reason }}</p>
                            @endif
                            <a class="button secondary" href="{{ route('teams.show', $application->team->routeIdentifier()) }}">Посмотреть команду</a>
                        </article>
                    @endforeach
                </div>
                <div class="app-team-pagination">{{ $applications->links() }}</div>
            @endif
            <div class="app-team-actions app-team-panel__actions">
                <button type="button" class="button primary" data-team-placeholder-action="find">Найти команду</button>
            </div>
        </section>

        <dialog class="mskba-modal app-team-registration-gate" data-team-registration-gate aria-labelledby="team-registration-gate-title">
            <header class="mskba-modal__header app-team-placeholder-dialog__header">
                <h2 id="team-registration-gate-title" tabindex="-1">Завершение регистрации</h2>
                <button type="button" class="icon-button" data-team-gate-cancel aria-label="Закрыть окно">
                    <svg aria-hidden="true"><use href="#close"/></svg>
                </button>
            </header>
            <div class="mskba-modal__body mskba-scroll app-team-placeholder-dialog__body">
                <p class="app-team-gate-explanation">
                    Для продолжения необходимо завершить регистрацию.
                    <button type="button"
                            class="app-team-gate-help-trigger"
                            data-team-gate-help-trigger
                            aria-label="Почему нужно завершить регистрацию?"
                            aria-describedby="team-registration-gate-tip"
                            aria-expanded="false">?</button>
                </p>
            </div>
            <footer class="mskba-modal__footer app-team-placeholder-dialog__footer">
                <button type="button" class="button secondary" data-team-gate-cancel>Отмена</button>
                <button type="button" class="button primary" data-team-gate-confirm>Завершить регистрацию</button>
            </footer>
            {{-- Outside the scrollable modal body so the overlay cannot be clipped. --}}
            <p id="team-registration-gate-tip"
               class="app-team-gate-tooltip"
               data-team-gate-tooltip
               role="tooltip"
               hidden>Всего один простой шаг и мы вернем тебя к поиску команды.</p>
        </dialog>

        <dialog class="mskba-modal app-team-placeholder-dialog" data-team-placeholder-dialog aria-labelledby="team-placeholder-title">
            <header class="mskba-modal__header app-team-placeholder-dialog__header">
                <h2 id="team-placeholder-title" tabindex="-1" data-team-placeholder-title>Функция готовится</h2>
                <button type="button" class="icon-button" data-team-placeholder-close aria-label="Закрыть окно">
                    <svg aria-hidden="true"><use href="#close"/></svg>
                </button>
            </header>
            <div class="mskba-modal__body mskba-scroll app-team-placeholder-dialog__body">
                <p>Мы дорабатываем этот сценарий с учётом последнего шага регистрации. Пока он недоступен, но твои команды и приглашения можно просматривать здесь.</p>
            </div>
            <footer class="mskba-modal__footer app-team-placeholder-dialog__footer">
                <button type="button" class="button primary" data-team-placeholder-close>Понятно</button>
            </footer>
        </dialog>
    </section>
@endsection
