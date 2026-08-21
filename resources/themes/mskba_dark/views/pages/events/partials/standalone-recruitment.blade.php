@php
    use App\Modules\Event\Domain\Enums\GameAdmissionCandidateTypeEnum;
    use App\Modules\Event\Domain\Enums\GameAdmissionDirectionEnum;
    use App\Modules\Event\Domain\Enums\GameAdmissionStatusEnum;
    use App\Modules\Event\Domain\Enums\GameRecruitmentModeEnum;
    use App\Modules\Event\Domain\Enums\GameTimingModeEnum;

    $routeParameters = [$event->routeIdentifier(), $game->id];
    $confirmed = $game->sides_confirmed_at !== null;
    $isTeamMode = $game->recruitment_mode === GameRecruitmentModeEnum::PREFORMED_TEAMS;
    $isIndividualMode = $game->recruitment_mode === GameRecruitmentModeEnum::INDIVIDUAL_DRAFT;
    $admissions = $managementMode ? $game->admissions : $relevantAdmissions;
    $acceptedTeams = $game->admissions
        ->where('candidate_type', GameAdmissionCandidateTypeEnum::TEAM)
        ->where('status', GameAdmissionStatusEnum::ACCEPTED)
        ->filter(fn ($admission) => $admission->team !== null)
        ->unique('team_id')
        ->values();
    $acceptedPlayersCount = $game->admissions
        ->where('candidate_type', GameAdmissionCandidateTypeEnum::USER)
        ->where('status', GameAdmissionStatusEnum::ACCEPTED)
        ->filter(fn ($admission) => $admission->user !== null)
        ->unique(fn ($admission) => $admission->user->canonical()->id)
        ->count();
    $statusLabels = [
        GameAdmissionStatusEnum::PENDING->value => 'Ожидает ответа',
        GameAdmissionStatusEnum::ACCEPTED->value => 'Принято',
        GameAdmissionStatusEnum::DECLINED->value => 'Отклонено',
        GameAdmissionStatusEnum::REVOKED->value => 'Отозвано',
    ];
    $directionLabels = [
        GameAdmissionDirectionEnum::APPLICATION->value => 'Заявка',
        GameAdmissionDirectionEnum::INVITATION->value => 'Приглашение',
        GameAdmissionDirectionEnum::SELECTION->value => 'Предварительно выбрано',
    ];
    $candidateName = static function ($admission): string {
        if ($admission->team) {
            return $admission->team->name;
        }
        $user = $admission->user?->canonical();
        if (!$user) {
            return 'Участник недоступен';
        }
        $profile = $user->profile;
        return trim(($profile?->first_name ?? '').' '.($profile?->last_name ?? '')) ?: $user->username ?: 'Пользователь #'.$user->id;
    };
@endphp

<section class="section-card mb-5" data-standalone-recruitment-panel>
    <div class="d-flex flex-wrap justify-content-between gap-3 align-items-start mb-3">
        <div>
            <span class="eyebrow">Участники игры</span>
            <h2 class="mb-1">{{ $isTeamMode ? 'Формирование сторон из команд' : 'Формирование сторон из игроков' }}</h2>
            <p class="form-text mb-0">
                {{ $confirmed
                    ? 'Стороны утверждены. До фактического старта утверждение можно снять и сформировать стороны заново.'
                    : ($isTeamMode
                        ? 'Команды могут подать заявку, организатор — пригласить команду. Из принятых кандидатов утверждаются две стороны.'
                        : 'Игроки подают заявки, организатор принимает их и формирует две сбалансированные стороны.') }}
            </p>
        </div>
        <span class="tournament-preparation-status {{ $confirmed ? 'is-ready' : 'is-pending' }}">
            <i class="ti {{ $confirmed ? 'ti-circle-check' : 'ti-users-plus' }}" aria-hidden="true"></i>
            {{ $confirmed ? 'Стороны утверждены' : 'Идёт формирование' }}
        </span>
    </div>

    @if($managementMode && $canManageRecruitment)
        @if($confirmed)
            <form method="POST" action="{{ route('events.games.recruitment.unconfirm', $routeParameters) }}" data-game-recruitment-ajax data-reload-page="1" onsubmit="return confirm('Снять утверждение сторон? Текущий снимок состава будет удалён, заявки и приглашения сохранятся.')">
                @csrf @method('DELETE')
                <button class="btn btn--danger btn--sm" type="submit">Снять утверждение сторон</button>
            </form>
        @else
            <div class="border rounded p-3 mb-4">
                <form method="POST" action="{{ route('events.games.recruitment.applications', $routeParameters) }}" data-game-recruitment-ajax>
                    @csrf @method('PATCH')
                    <input type="hidden" name="enabled" value="0">
                    <label class="d-flex gap-3 align-items-start">
                        <input type="checkbox" name="enabled" value="1" @checked($game->accepts_applications)>
                        <span><strong>Принимать новые заявки</strong><br><small class="form-text">Приглашения организатора можно отправлять независимо от этой настройки.</small></span>
                    </label>
                    <button class="btn btn--secondary btn--sm mt-3" type="submit">Сохранить настройку</button>
                </form>
            </div>

            <div class="border rounded p-3 mb-4" data-game-candidate-search data-search-url="{{ route('events.games.recruitment.candidates', $routeParameters) }}">
                <h3 class="h5">{{ $isTeamMode ? 'Пригласить команду' : 'Пригласить игрока' }}</h3>
                <form method="POST" action="{{ route('events.games.recruitment.invite', $routeParameters) }}" data-game-recruitment-ajax>
                    @csrf
                    <label class="form-label" for="standalone-game-candidate-search">{{ $isTeamMode ? 'Команда' : 'Игрок' }}</label>
                    <input id="standalone-game-candidate-search" class="form-control" type="search" autocomplete="off" placeholder="Начните вводить {{ $isTeamMode ? 'название команды' : 'имя или логин' }}…" data-game-candidate-query>
                    <input type="hidden" name="{{ $isTeamMode ? 'team_id' : 'user_id' }}" data-game-candidate-id>
                    <div class="entity-predictive-search__results mt-2" data-game-candidate-results hidden></div>
                    <button class="btn btn--primary btn--sm mt-3" type="submit" data-game-candidate-submit disabled>Отправить приглашение</button>
                </form>
            </div>

            @if($isTeamMode && $acceptedTeams->count() >= 2)
                <div class="border rounded p-3 mb-4">
                    <h3 class="h5">Утвердить две команды</h3>
                    <form class="row g-3" method="POST" action="{{ route('events.games.recruitment.teams.confirm', $routeParameters) }}" data-game-recruitment-ajax data-reload-page="1">
                        @csrf
                        <div class="col-md-6">
                            <label class="form-label">Сторона A</label>
                            <select class="form-select" name="team_a_id" required>
                                <option value="">Выберите команду</option>
                                @foreach($acceptedTeams as $admission)<option value="{{ $admission->team_id }}">{{ $admission->team->name }}</option>@endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Сторона B</label>
                            <select class="form-select" name="team_b_id" required>
                                <option value="">Выберите команду</option>
                                @foreach($acceptedTeams as $admission)<option value="{{ $admission->team_id }}">{{ $admission->team->name }}</option>@endforeach
                            </select>
                        </div>
                        <div class="col-12"><button class="btn btn--primary" type="submit">Утвердить стороны</button></div>
                    </form>
                </div>
            @elseif($isTeamMode)
                <div class="alert alert-info mb-4">Для утверждения сторон нужно минимум две принятые команды. Сейчас: {{ $acceptedTeams->count() }}.</div>
            @endif

            @if($isIndividualMode)
                <div class="border rounded p-3 mb-4"
                     data-balanced-formation
                     data-preview-url="{{ route('events.games.recruitment.formation.preview', $routeParameters) }}"
                     data-apply-url="{{ route('events.games.recruitment.formation.apply', $routeParameters) }}"
                     data-allow-logo-upload="0"
                     data-reload-on-apply="1">
                    <h3 class="h5">Balanced-формирование</h3>
                    <p class="form-text">Принято игроков: {{ $acceptedPlayersCount }}. Для текущего формата требуется минимум {{ $game->side_a_size * 2 }}.</p>
                    <form class="row g-3" data-balanced-formation-form>
                        <div class="col-md-8">
                            <label class="form-label">Источник оценки</label>
                            <select class="form-select" name="assessment_source">
                                @foreach($assessmentSources as $source)<option value="{{ $source->value }}">{{ $source->label() }}</option>@endforeach
                            </select>
                        </div>
                        <div class="col-md-4 d-flex align-items-end"><button class="btn btn--primary w-100" type="submit">Сформировать</button></div>
                    </form>
                    <div class="alert mt-3" data-balanced-formation-message hidden></div>
                    <div class="row g-3 mt-2" data-balanced-formation-preview></div>
                    <button class="btn btn--primary mt-3" type="button" data-balanced-formation-apply hidden>Утвердить стороны</button>
                </div>
            @endif
        @endif

        <div class="border rounded p-3 mb-4">
            <h3 class="h5">Основные параметры игры</h3>
            <form class="row g-3" method="POST" action="{{ route('events.games.recruitment.configuration', $routeParameters) }}" data-game-recruitment-ajax data-reload-page="1">
                @csrf @method('PUT')
                <div class="col-md-4"><label class="form-label">Формат</label><select class="form-select" name="game_format" data-recruitment-game-format>@foreach(\App\Modules\Event\Domain\Enums\GameFormatEnum::cases() as $format)<option value="{{ $format->value }}" @selected($game->format === $format)>{{ $format->label() }}</option>@endforeach</select></div>
                <div class="col-md-4"><label class="form-label">Игроков A</label><input class="form-control" type="number" min="1" max="7" name="side_a_size" value="{{ $game->side_a_size }}" required data-recruitment-side-a></div>
                <div class="col-md-4"><label class="form-label">Игроков B</label><input class="form-control" type="number" min="1" max="7" name="side_b_size" value="{{ $game->side_b_size }}" required data-recruitment-side-b></div>
                <div class="col-md-4"><label class="form-label">Подсчёт</label><select class="form-select" name="scoring_type">@foreach(\App\Modules\Event\Domain\Enums\GameScoringTypeEnum::cases() as $scoring)<option value="{{ $scoring->value }}" @selected($game->scoring_type === $scoring)>{{ $scoring->label() }}</option>@endforeach</select></div>
                <div class="col-md-4"><label class="form-label">Хронометраж</label><select class="form-select" name="timing_mode" data-recruitment-timing-mode>@foreach(GameTimingModeEnum::cases() as $mode)<option value="{{ $mode->value }}" @selected($game->timing_mode === $mode)>{{ $mode->label() }}</option>@endforeach</select></div>
                <div class="col-md-4"><label class="form-label">Периодов</label><select class="form-select" name="periods_count" data-recruitment-periods-count @disabled($game->timing_mode !== GameTimingModeEnum::PERIODS)><option value="4" @selected($game->periods_count === 4)>4</option><option value="2" @selected($game->periods_count === 2)>2</option></select></div>
                <div class="col-12"><button class="btn btn--secondary btn--sm" type="submit">Сохранить параметры</button></div>
            </form>
        </div>

        <h3 class="h5">Заявки и приглашения</h3>
        @forelse($admissions->sortByDesc('id') as $admission)
            <article class="border rounded p-3 mb-2">
                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-start">
                    <div>
                        <strong>{{ $candidateName($admission) }}</strong>
                        <div class="form-text">{{ $directionLabels[$admission->direction->value] ?? $admission->direction->value }} · {{ $statusLabels[$admission->status->value] ?? $admission->status->value }}</div>
                    </div>
                    @if($admission->status === GameAdmissionStatusEnum::PENDING)
                        <div class="d-flex flex-wrap gap-2">
                            @if($managementMode && $admission->direction === GameAdmissionDirectionEnum::APPLICATION)
                                <form method="POST" action="{{ route('events.games.recruitment.respond', [...$routeParameters, $admission->id]) }}" data-game-recruitment-ajax>@csrf<input type="hidden" name="decision" value="accepted"><button class="btn btn--primary btn--sm">Принять</button></form>
                                <form method="POST" action="{{ route('events.games.recruitment.respond', [...$routeParameters, $admission->id]) }}" data-game-recruitment-ajax>@csrf<input type="hidden" name="decision" value="declined"><button class="btn btn--secondary btn--sm">Отклонить</button></form>
                            @elseif(!$managementMode && $admission->direction === GameAdmissionDirectionEnum::INVITATION)
                                <form method="POST" action="{{ route('events.games.recruitment.respond', [...$routeParameters, $admission->id]) }}" data-game-recruitment-ajax>@csrf<input type="hidden" name="decision" value="accepted"><button class="btn btn--primary btn--sm">Принять</button></form>
                                <form method="POST" action="{{ route('events.games.recruitment.respond', [...$routeParameters, $admission->id]) }}" data-game-recruitment-ajax>@csrf<input type="hidden" name="decision" value="declined"><button class="btn btn--secondary btn--sm">Отклонить</button></form>
                            @endif
                            <form method="POST" action="{{ route('events.games.recruitment.revoke', [...$routeParameters, $admission->id]) }}" data-game-recruitment-ajax>@csrf @method('DELETE')<button class="btn btn--danger btn--sm">Отозвать</button></form>
                        </div>
                    @elseif($admission->status === GameAdmissionStatusEnum::ACCEPTED && !$confirmed)
                        <form method="POST" action="{{ route('events.games.recruitment.revoke', [...$routeParameters, $admission->id]) }}" data-game-recruitment-ajax>@csrf @method('DELETE')<button class="btn btn--danger btn--sm">Убрать из пула</button></form>
                    @endif
                </div>
            </article>
        @empty
            <p class="form-text">Заявок и приглашений пока нет.</p>
        @endforelse
    @else
        @if(!$confirmed && $game->accepts_applications)
            @auth
                @if($isIndividualMode)
                    <form method="POST" action="{{ route('events.games.recruitment.apply', $routeParameters) }}" data-game-recruitment-ajax>
                        @csrf
                        <button class="btn btn--primary btn--sm" type="submit">Подать заявку на участие</button>
                    </form>
                @elseif($manageableTeams->isNotEmpty())
                    <form class="row g-2 align-items-end" method="POST" action="{{ route('events.games.recruitment.apply', $routeParameters) }}" data-game-recruitment-ajax>
                        @csrf
                        <div class="col-md-8"><label class="form-label">Команда, которую вы представляете</label><select class="form-select" name="team_id" required>@foreach($manageableTeams as $team)<option value="{{ $team->id }}">{{ $team->name }}</option>@endforeach</select></div>
                        <div class="col-md-4"><button class="btn btn--primary btn--sm w-100" type="submit">Подать заявку</button></div>
                    </form>
                @endif
            @else
                <a class="btn btn--primary btn--sm" href="{{ route('login') }}">Войти, чтобы подать заявку</a>
            @endauth
        @elseif(!$confirmed)
            <div class="alert alert-info mb-0">Организатор временно не принимает новые заявки.</div>
        @endif

        @if($relevantAdmissions->isNotEmpty())
            <div class="mt-4">
                <h3 class="h5">Ваши заявки и приглашения</h3>
                @foreach($relevantAdmissions->sortByDesc('id') as $admission)
                    <article class="border rounded p-3 mb-2">
                        <strong>{{ $candidateName($admission) }}</strong>
                        <div class="form-text mb-2">{{ $directionLabels[$admission->direction->value] ?? $admission->direction->value }} · {{ $statusLabels[$admission->status->value] ?? $admission->status->value }}</div>
                        @if($admission->status === GameAdmissionStatusEnum::PENDING && $admission->direction === GameAdmissionDirectionEnum::INVITATION)
                            <div class="d-flex gap-2">
                                <form method="POST" action="{{ route('events.games.recruitment.respond', [...$routeParameters, $admission->id]) }}" data-game-recruitment-ajax>@csrf<input type="hidden" name="decision" value="accepted"><button class="btn btn--primary btn--sm">Принять</button></form>
                                <form method="POST" action="{{ route('events.games.recruitment.respond', [...$routeParameters, $admission->id]) }}" data-game-recruitment-ajax>@csrf<input type="hidden" name="decision" value="declined"><button class="btn btn--secondary btn--sm">Отклонить</button></form>
                            </div>
                        @elseif($admission->status->isActive())
                            <form method="POST" action="{{ route('events.games.recruitment.revoke', [...$routeParameters, $admission->id]) }}" data-game-recruitment-ajax>@csrf @method('DELETE')<button class="btn btn--secondary btn--sm">Отозвать</button></form>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif
    @endif
</section>
