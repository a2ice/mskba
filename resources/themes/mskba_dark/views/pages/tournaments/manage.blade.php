@php
    $breadcrumbs = [
        ['label' => 'Турниры', 'url' => route('tournaments.index')],
        ['label' => $tournament->title, 'url' => route('tournaments.show', $tournament->routeIdentifier())],
        ['label' => 'Управление'],
    ];
    $canManageMain = $isOwner || $effectivePermissions->contains(\App\Modules\Tournament\Domain\Enums\TournamentPermissionEnum::MANAGE_DESCRIPTION);
    $canManageStaff = $effectivePermissions->contains(\App\Modules\Tournament\Domain\Enums\TournamentPermissionEnum::MANAGE_STAFF);
    $canManageStatus = $effectivePermissions->contains(\App\Modules\Tournament\Domain\Enums\TournamentPermissionEnum::MANAGE_STATUS);
    $canDeleteTournament = $effectivePermissions->contains(\App\Modules\Tournament\Domain\Enums\TournamentPermissionEnum::DELETE);
    $participantPoolLocked = (bool) $participantPoolLocked;
@endphp

@extends('theme::layouts.section-sidebar', [
    'title' => 'Управление · '.$tournament->title,
    'sectionId' => 'tournament-management',
    'sectionClass' => 'tournaments-section tournament-management-section',
    'contentTitle' => 'Управление турниром',
    'contentSubtitle' => $tournament->title,
    'sidebarLabel' => 'Разделы управления турниром',
])

@section('section-sidebar')
    <div class="section-sidebar-block">
        <h2 class="section-sidebar-block__title">Управление</h2>
        <ul class="sidebar-nav nav flex-column">
            @if($pendingMembership)<li class="nav-item"><a class="nav-link" href="#invitation">Приглашение</a></li>@endif
            @if($canManageMain)<li class="nav-item"><a class="nav-link" href="#main">Основное</a></li>@endif
            @if($canManageGames)<li class="nav-item"><a class="nav-link" href="#participants">Участники</a></li>@if($entries->count() >= 2 && $participantPoolLocked)<li class="nav-item"><a class="nav-link" href="#matches">Матчи и расписание</a></li>@endif @endif
            @if($canManageStaff)<li class="nav-item"><a class="nav-link" href="#staff">Ответственные</a></li>@endif
            @if($canManageStatus)<li class="nav-item"><a class="nav-link" href="#status">Статус</a></li>@endif
            @if($canDeleteTournament)<li class="nav-item"><a class="nav-link" href="#delete">Удаление</a></li>@endif
        </ul>
    </div>
    <div class="section-sidebar-block">
        <h2 class="section-sidebar-block__title">Турнир</h2>
        <a class="btn btn--secondary btn--sm" href="{{ route('tournaments.show', $tournament->routeIdentifier()) }}">Публичная страница</a>
    </div>
@endsection

@section('section-heading-action')
    <span class="tournament-preparation-status {{ $preparationStatus['modifier'] }}">
        <i class="ti {{ $preparationStatus['icon'] }}" aria-hidden="true"></i>
        {{ $preparationStatus['label'] }}
    </span>
@endsection

@section('section-content')
    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif

    @if($pendingMembership)
        <div class="event-card mb-4" id="invitation">
            <h2>Приглашение</h2>
            <p>Вам предлагают стать ответственным за турнир с правами:</p>
            <ul>@foreach($pendingMembership->contract->permissions as $item)<li>{{ \App\Modules\Tournament\Domain\Enums\TournamentPermissionEnum::tryFrom($item->permission)?->label() }}</li>@endforeach</ul>
            <div class="d-flex gap-2">
                <form method="POST" action="{{ route('tournaments.staff.respond', [$tournament->routeIdentifier(), $pendingMembership]) }}">@csrf<input type="hidden" name="decision" value="accepted"><button class="btn btn--primary">Принять</button></form>
                <form method="POST" action="{{ route('tournaments.staff.respond', [$tournament->routeIdentifier(), $pendingMembership]) }}">@csrf<input type="hidden" name="decision" value="declined"><button class="btn btn--secondary">Отклонить</button></form>
            </div>
        </div>
    @endif

    @if($isOwner)
        <div class="event-card mb-4" id="main"><h2>Основные данные</h2><form method="POST" action="{{ route('tournaments.update', $tournament->routeIdentifier()) }}" enctype="multipart/form-data">@csrf @method('PUT') @include('theme::pages.tournaments.partials.form')</form></div>
    @elseif($effectivePermissions->contains(\App\Modules\Tournament\Domain\Enums\TournamentPermissionEnum::MANAGE_DESCRIPTION))
        <div class="event-card mb-4" id="main"><h2>Описание и обложка</h2>
            <form method="POST" action="{{ route('tournaments.update', $tournament->routeIdentifier()) }}" enctype="multipart/form-data">@csrf @method('PUT')
                <input type="hidden" name="title" value="{{ $tournament->title }}"><input type="hidden" name="alias" value="{{ $tournament->alias }}">
                <input type="hidden" name="starts_on" value="{{ $tournament->starts_on->format('Y-m-d') }}">@if($tournament->ends_on)<input type="hidden" name="ends_on" value="{{ $tournament->ends_on->format('Y-m-d') }}">@endif
                @if($tournament->format)<input type="hidden" name="format" value="{{ $tournament->format->value }}">@endif
                <input type="hidden" name="recruitment_mode" value="{{ $tournament->recruitment_mode->value }}">
                <div class="mb-3"><label class="form-label">Краткое описание</label><textarea class="form-control" name="short_description" maxlength="1000">{{ old('short_description', $tournament->short_description) }}</textarea></div>
                <div class="mb-3"><label class="form-label">Полное описание</label><textarea class="form-control" name="full_description" rows="8" maxlength="20000">{{ old('full_description', $tournament->full_description) }}</textarea></div>
                <div class="mb-3"><label class="form-label">Обложка</label><input class="form-control" type="file" name="cover" accept="image/*"></div>
                <button class="btn btn--primary">Сохранить описание</button>
            </form>
        </div>
    @endif

    @if($effectivePermissions->contains(\App\Modules\Tournament\Domain\Enums\TournamentPermissionEnum::MANAGE_STATUS))
        <div class="event-card mb-4" id="status"><h2>Статус</h2><form method="POST" action="{{ route('tournaments.status', $tournament->routeIdentifier()) }}">@csrf @method('PATCH')<div class="row g-3"><div class="col-md-4"><select class="form-select" name="status">@foreach($statuses as $status)<option value="{{ $status->value }}" @selected($tournament->status === $status)>{{ $status->label() }}</option>@endforeach</select></div><div class="col-md-8"><input class="form-control" name="status_comment" value="{{ $tournament->status_comment }}" maxlength="2000" placeholder="Комментарий к статусу"></div></div><button class="btn btn--primary btn--sm mt-3">Сохранить статус</button></form></div>
    @endif

    @if($effectivePermissions->contains(\App\Modules\Tournament\Domain\Enums\TournamentPermissionEnum::MANAGE_STAFF))
        <div class="event-card mb-4" id="staff">
            <h2>Ответственные</h2>
            <form method="POST" action="{{ route('tournaments.staff.invite', $tournament->routeIdentifier()) }}" class="mb-4">@csrf
                <div class="mb-3">@include('theme::partials.forms.entity-predictive-search', [
                    'id' => 'tournamentStaffUser',
                    'name' => 'user_id',
                    'label' => 'Подтверждённый пользователь',
                    'placeholder' => 'Начните вводить имя или логин…',
                    'searchUrl' => route('tournaments.staff.candidates', $tournament->routeIdentifier()),
                ])</div>
                <div class="mb-3">@foreach($permissionOptions as $permission)<label class="d-block mb-2"><input type="checkbox" name="permissions[]" value="{{ $permission->value }}" @disabled(!$effectivePermissions->contains($permission))> {{ $permission->label() }}</label>@endforeach</div>
                <button class="btn btn--primary btn--sm">Отправить приглашение</button>
            </form>
            @foreach($staffMemberships as $membership)
                <div class="border rounded p-3 mb-3">
                    <strong>{{ $membership->user->profile?->first_name }} {{ $membership->user->profile?->last_name }} ({{ $membership->user->username }})</strong>
                    <div class="text-muted mb-2">{{ $membership->invitation_status->label() }} · {{ $membership->contract->status->label() }}</div>
                    @if(in_array($membership->invitation_status, [\App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum::PENDING, \App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum::ACCEPTED], true))
                        <form method="POST" action="{{ route('tournaments.staff.update', [$tournament->routeIdentifier(), $membership]) }}" class="mb-2">@csrf @method('PATCH')
                            @foreach($permissionOptions as $permission)<label class="d-block mb-1"><input type="checkbox" name="permissions[]" value="{{ $permission->value }}" @checked($membership->contract->permissions->contains('permission', $permission->value)) @disabled(!$effectivePermissions->contains($permission))> {{ $permission->label() }}</label>@endforeach
                            <button class="btn btn--secondary btn--sm mt-2">Обновить права</button>
                        </form>
                        <form method="POST" action="{{ route('tournaments.staff.revoke', [$tournament->routeIdentifier(), $membership]) }}">@csrf @method('DELETE')<button class="btn btn--danger btn--sm">Отозвать</button></form>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    @if($canManageGames)
        <div class="event-card mb-4" id="participants">
            <h2>Участники турнира</h2>
            <p><strong>Режим:</strong> {{ $tournament->recruitment_mode->label() }}</p>
            @php($hasFormedTeams = $entries->contains(fn ($entry) => $entry->source === \App\Modules\Tournament\Domain\Enums\TournamentEntrySourceEnum::ASSEMBLED))
            @if($acceptsAdmissions)
            <form method="POST" action="{{ route('tournaments.admissions.invite', $tournament->routeIdentifier()) }}" class="mb-4" data-tournament-candidate-search data-search-url="{{ route('tournaments.admissions.candidates', $tournament->routeIdentifier()) }}">@csrf
                @include('theme::partials.forms.entity-predictive-search', [
                    'id' => 'tournamentAdmissionCandidate',
                    'name' => $tournament->recruitment_mode === \App\Modules\Tournament\Domain\Enums\TournamentRecruitmentModeEnum::PREFORMED_TEAMS ? 'team_id' : 'user_id',
                    'label' => $tournament->recruitment_mode === \App\Modules\Tournament\Domain\Enums\TournamentRecruitmentModeEnum::PREFORMED_TEAMS ? 'Команда' : 'Игрок',
                    'placeholder' => 'Начните вводить название или имя…',
                    'searchUrl' => route('tournaments.admissions.candidates', $tournament->routeIdentifier()),
                ])
                <button class="btn btn--primary btn--sm mt-3">Пригласить</button>
            </form>
            @if($tournament->recruitment_mode === \App\Modules\Tournament\Domain\Enums\TournamentRecruitmentModeEnum::PREFORMED_TEAMS && $entries->count() >= 2)
                <form class="mb-4" method="POST" action="{{ route('tournaments.participant-pool.lock', $tournament->routeIdentifier()) }}" onsubmit="return confirm('Завершить набор команд? Новые заявки и отзыв принятых команд будут закрыты.')">
                    @csrf
                    <button class="btn btn--primary" type="submit">Завершить набор команд</button>
                </form>
            @endif
            @else
                <div class="alert alert-info mb-4">
                    @if($hasFormedTeams ?? false)
                        Приём заявок и приглашений закрыт: команды уже сформированы. Чтобы изменить пул участников, сначала расформируйте команды.
                    @elseif($participantPoolLocked && $tournament->recruitment_mode === \App\Modules\Tournament\Domain\Enums\TournamentRecruitmentModeEnum::PREFORMED_TEAMS)
                        Набор готовых команд завершён. Принятые команды зафиксированы для формирования расписания.
                    @elseif($competitionStarted)
                        Приём заявок и приглашений закрыт: турнир уже начался.
                    @elseif($tournament->status !== \App\Modules\Tournament\Domain\Enums\TournamentStatusEnum::CONFIRMED)
                        Приём заявок и приглашений закрыт из-за текущего статуса турнира.
                    @else
                        Приём заявок и приглашений на этот турнир закрыт.
                    @endif
                </div>
                @if($participantPoolLocked && $tournament->recruitment_mode === \App\Modules\Tournament\Domain\Enums\TournamentRecruitmentModeEnum::PREFORMED_TEAMS && ! $matches->contains(fn ($match) => $match->game_id !== null))
                    <form class="mb-4" method="POST" action="{{ route('tournaments.participant-pool.unlock', $tournament->routeIdentifier()) }}" onsubmit="return confirm('Возобновить набор команд? Черновая схема матчей будет удалена.')">
                        @csrf @method('DELETE')
                        <button class="btn btn--secondary" type="submit">Возобновить набор команд</button>
                    </form>
                @endif
            @endif
            @forelse($admissions as $admission)
                <div class="border rounded p-3 mb-3">
                    <strong>{{ $admission->team?->name ?? trim(($admission->user?->profile?->first_name ?? '').' '.($admission->user?->profile?->last_name ?? '')) ?: $admission->user?->username }}</strong>
                    <div class="text-muted">{{ $admission->direction->value === 'application' ? 'Заявка' : 'Приглашение' }}@if($admission->roles?->isNotEmpty()) · {{ $admission->roles->map->label()->join(', ') }}@endif · {{ $admission->status->label() }}</div>
                    @if($admission->direction->value === 'application' && $admission->status === \App\Modules\Tournament\Domain\Enums\TournamentAdmissionStatusEnum::PENDING)
                        <div class="d-flex gap-2 mt-2">
                            @if($acceptsAdmissions)
                            <form method="POST" action="{{ route('tournaments.admissions.respond', [$tournament->routeIdentifier(), $admission]) }}">@csrf<input type="hidden" name="decision" value="accepted"><button class="btn btn--primary btn--sm">Принять</button></form>
                            @endif
                            <form method="POST" action="{{ route('tournaments.admissions.respond', [$tournament->routeIdentifier(), $admission]) }}">@csrf<input type="hidden" name="decision" value="declined"><button class="btn btn--secondary btn--sm">Отклонить</button></form>
                        </div>
                    @endif
                    @if($admission->status === \App\Modules\Tournament\Domain\Enums\TournamentAdmissionStatusEnum::PENDING || ($admission->status === \App\Modules\Tournament\Domain\Enums\TournamentAdmissionStatusEnum::ACCEPTED && ! $participantPoolLocked))
                        <form class="mt-2" method="POST" action="{{ route('tournaments.admissions.revoke', [$tournament->routeIdentifier(), $admission]) }}">@csrf @method('DELETE')<button class="btn btn--danger btn--sm">Отозвать</button></form>
                    @elseif($admission->status === \App\Modules\Tournament\Domain\Enums\TournamentAdmissionStatusEnum::ACCEPTED && $participantPoolLocked)
                        <p class="form-text mt-2">Чтобы отозвать участника, сначала {{ $tournament->recruitment_mode === \App\Modules\Tournament\Domain\Enums\TournamentRecruitmentModeEnum::PREFORMED_TEAMS ? 'возобновите набор' : 'расформируйте команды' }}.</p>
                    @endif
                </div>
            @empty<p>Заявок и приглашений пока нет.</p>@endforelse
            @if($entries->isNotEmpty())
                <h3 class="mt-4">{{ $tournament->recruitment_mode === \App\Modules\Tournament\Domain\Enums\TournamentRecruitmentModeEnum::PREFORMED_TEAMS ? 'Допущенные команды' : 'Допущенные участники' }}</h3>
                <ul>@foreach($entries as $entry)<li>{{ $entry->name }} · {{ $entry->effective_members_count }} {{ trans_choice('участник|участника|участников', $entry->effective_members_count) }}</li>@endforeach</ul>
            @endif
        </div>
        @if($tournament->recruitment_mode === \App\Modules\Tournament\Domain\Enums\TournamentRecruitmentModeEnum::INDIVIDUAL_DRAFT && $tournament->format?->sideSize() !== 1 && ! $matches->contains(fn ($match) => $match->game_id !== null))
            <div class="event-card mb-4" @if(! $hasFormedTeams) data-tournament-formation data-preview-url="{{ route('tournaments.formation.preview', $tournament->routeIdentifier()) }}" data-apply-url="{{ route('tournaments.formation.apply', $tournament->routeIdentifier()) }}" @endif>
                @if($hasFormedTeams)
                    <h2>Команды сформированы</h2>
                    <p>Чтобы изменить составы, названия, логотипы или формат турнира, сначала расформируйте команды. Подтверждённые игроки останутся в пуле.@if($matches->isNotEmpty()) Черновая круговая схема также будет удалена.@endif</p>
                    <form class="mt-3" method="POST" action="{{ route('tournaments.formation.disband', $tournament->routeIdentifier()) }}" onsubmit="return confirm('Расформировать команды? Составы и черновая круговая схема будут удалены, подтверждённые игроки останутся в пуле.')">
                        @csrf @method('DELETE')
                        <button class="btn btn--danger" type="submit">Расформировать команды</button>
                    </form>
                @else
                <h2>Balanced-формирование</h2><p>В пуле: {{ $acceptedPlayerCount }} игроков. Preview можно пересчитать и скорректировать перетаскиванием.</p>
                <form class="row g-3" data-tournament-formation-form>
                    <div class="col-md-6"><label class="form-label">Источник оценки</label><select class="form-select" name="assessment_source">@foreach($assessmentSources as $source)<option value="{{ $source->value }}">{{ $source->label() }}</option>@endforeach</select></div>
                    <div class="col-md-3"><label class="form-label">Команд</label><input class="form-control" type="number" name="team_count" value="2" min="2" max="64" required></div>
                    <div class="col-md-3 d-flex align-items-end"><button class="btn btn--primary w-100" type="submit">Сформировать</button></div>
                </form>
                <div class="alert mt-3" data-tournament-formation-message hidden></div>
                <div class="row g-3 mt-2" data-tournament-formation-preview></div>
                <button class="btn btn--primary mt-3" type="button" data-tournament-formation-apply hidden>Утвердить команды</button>
                @endif
            </div>
        @endif
        @if($entries->count() >= 2 && $participantPoolLocked)
            @php($scheduleLocked = $matches->contains(fn ($match) => $match->game_id !== null))
            @php($pairCount = intdiv($entries->count() * ($entries->count() - 1), 2))
            @php($currentLegs = $matches->isEmpty() ? null : ($pairCount > 0 && $matches->count() === $pairCount * 2 ? 2 : 1))
            <div class="event-card tournament-scheme mb-4" data-tournament-schedule data-preview-url="{{ route('tournaments.schedule.preview', $tournament->routeIdentifier()) }}" data-apply-url="{{ route('tournaments.schedule.apply', $tournament->routeIdentifier()) }}" data-has-matches="{{ $matches->isNotEmpty() ? '1' : '0' }}" data-schedule-locked="{{ $scheduleLocked ? '1' : '0' }}">
                <h2 class="mb-1">Круговая схема</h2>
                <p class="mb-3">Выберите, сколько раз каждая пара команд встретится в турнире.</p>
                <form data-tournament-schedule-form>
                    <fieldset class="tournament-scheme__choices" @disabled($scheduleLocked)>
                        <legend class="visually-hidden">Количество кругов</legend>
                        <label class="tournament-scheme__choice">
                            <input type="radio" name="legs" value="1" @checked($currentLegs === 1)>
                            <span><strong>Один круг</strong><small>Каждая пара команд играет один матч.</small></span>
                        </label>
                        <label class="tournament-scheme__choice">
                            <input type="radio" name="legs" value="2" @checked($currentLegs === 2)>
                            <span><strong>Два круга</strong><small>Каждая пара играет два матча с переменой сторон.</small></span>
                        </label>
                    </fieldset>
                </form>
                <div class="alert mt-3" data-tournament-schedule-message hidden></div>
                @if($scheduleLocked)
                    <div class="alert alert-info mt-3">Схему нельзя изменить: один или несколько матчей уже назначены.</div>
                @endif
            </div>
            <div class="event-card mb-4" id="matches"><h2><span title="Добавляйте отдельные матчи, меняйте их порядок и назначайте время и площадку">Матчи и расписание</span></h2>
                @if($matches->isNotEmpty())
                    <form method="POST" action="{{ route('tournaments.matches.reorder', $tournament->routeIdentifier()) }}" data-tournament-match-order>@csrf @method('PATCH')
                        <div class="tournament-match-order" data-tournament-match-list>
                            @foreach($matches as $match)
                                <div class="tournament-match-order__row" draggable="true" data-tournament-match-row data-match-id="{{ $match->id }}">
                                    <input type="hidden" name="positions[{{ $match->id }}]" value="{{ $match->sequence }}" data-match-position>
                                    <span class="tournament-match-order__handle" aria-hidden="true"><i class="ti ti-grip-vertical"></i></span>
                                    <span class="tournament-match-order__position" data-match-position-label>{{ $match->sequence }}</span>
                                    <strong class="tournament-match-order__title">{{ $match->entryA->name }} — {{ $match->entryB->name }}</strong>
                                    @if($match->game)<a class="fc-link" href="{{ route('events.show', $match->game->event->routeIdentifier()) }}">Открыть игру</a>@endif
                                    @unless($match->game)<button class="btn btn--danger btn--sm tournament-match-order__delete" type="submit" form="delete-match-{{ $match->id }}">Удалить</button>@endunless
                                </div>
                            @endforeach
                        </div>
                        <button class="btn btn--secondary btn--sm">Сохранить порядок</button>
                    </form>
                    @foreach($matches as $match)@unless($match->game)<form id="delete-match-{{ $match->id }}" method="POST" action="{{ route('tournaments.matches.destroy', [$tournament->routeIdentifier(), $match]) }}">@csrf @method('DELETE')</form>@endunless @endforeach
                @endif
                <hr>
                @if(! $competitionStarted)
                <h3>Добавить отдельный матч</h3>
                <p class="form-text mb-3">Создаёт дополнительную пару между двумя существующими командами. Новые участники здесь не добавляются.</p>
                <form method="POST" action="{{ route('tournaments.matches.store', $tournament->routeIdentifier()) }}" class="row g-3 my-4">@csrf
                    <div class="col-md-9 d-flex flex-wrap align-items-center gap-3">
                        @foreach(['entry_a_id' => 'Сторона A', 'entry_b_id' => 'Сторона B'] as $field => $label)
                            <div>
                                @include('theme::partials.forms.entity-predictive-search', [
                                    'id' => 'tournamentMatch'.($field === 'entry_a_id' ? 'A' : 'B'),
                                    'name' => $field,
                                    'label' => $label,
                                    'placeholder' => 'Начните вводить название…',
                                    'minimumLength' => 1,
                                    'initialMessage' => 'Введите часть названия и выберите команду.',
                                    'options' => $entries->map(fn ($entry) => ['id' => $entry->id, 'label' => $entry->name, 'meta' => 'Команда #'.$entry->id]),
                                ])
                            </div>
                        @endforeach
                    </div>
                    <div class="col-md-3 d-flex align-items-center">
                        <button class="btn btn--primary btn--sm w-100">Добавить</button>
                    </div>
                </form>
                @else
                    <div class="alert alert-info my-4">Добавление новых матчей закрыто: турнир уже начался.</div>
                @endif
                <hr>
                @if($matches->isNotEmpty())
                    @foreach($matches as $match)@if(!$match->game)
                        <details class="border rounded p-3 mt-3"><summary><strong>Назначить: {{ $match->entryA->name }} — {{ $match->entryB->name }}</strong></summary>
                            <form class="row g-3 mt-2" method="POST" action="{{ route('tournaments.matches.schedule', [$tournament->routeIdentifier(), $match]) }}">@csrf
                                <div class="col-md-6"><label class="form-label" for="match-{{ $match->id }}-starts">Начало</label><input class="form-control" id="match-{{ $match->id }}-starts" type="datetime-local" name="starts_at" value="{{ old('starts_at', $tournament->starts_on->isAfter(today()) ? $tournament->starts_on->setTime(19, 0)->format('Y-m-d\TH:i') : now()->addHour()->startOfHour()->format('Y-m-d\TH:i')) }}" required></div>
                                <div class="col-md-6"><label class="form-label" for="match-{{ $match->id }}-duration">Длительность, минут</label><input class="form-control" id="match-{{ $match->id }}-duration" type="number" name="duration_minutes" value="{{ old('duration_minutes', 90) }}" min="1" max="1440" required></div>
                                <div class="col-12">@include('theme::partials.venues.predictive-selector', ['id' => 'match'.$match->id.'Venue', 'startInput' => '#match-'.$match->id.'-starts', 'durationInput' => '#match-'.$match->id.'-duration', 'confirmedOnly' => true])</div>
                                <div class="col-md-4"><label class="form-label">Формат</label><select class="form-select" name="game_format" data-match-game-format>@foreach($formats as $format)<option value="{{ $format->value }}" @selected($tournament->format === $format)>{{ $format->label() }}</option>@endforeach</select></div>
                                <div class="col-md-4"><label class="form-label">Хронометраж</label><select class="form-select" name="timing_mode" data-match-timing-mode><option value="whole_game">Игра целиком</option><option value="periods">По периодам</option></select></div>
                                <div class="col-md-4" data-match-periods-field><label class="form-label">Периодов</label><select class="form-select" name="periods_count" data-match-periods-count disabled><option value="4">4</option><option value="2">2</option></select></div>
                                <div class="col-12"><button class="btn btn--primary" type="submit">Создать игру и бронь</button></div>
                            </form>
                        </details>
                    @else
                        <details class="border rounded p-3 mt-3"><summary><strong>Перенести: {{ $match->entryA->name }} — {{ $match->entryB->name }}</strong></summary>
                            <form class="row g-3 mt-2" method="POST" action="{{ route('tournaments.matches.reschedule', [$tournament->routeIdentifier(), $match]) }}">@csrf @method('PUT')
                                <div class="col-md-6"><label class="form-label" for="match-{{ $match->id }}-move-starts">Новое начало</label><input class="form-control" id="match-{{ $match->id }}-move-starts" type="datetime-local" name="starts_at" value="{{ $match->game->event->starts_at->format('Y-m-d\TH:i') }}" required></div>
                                <div class="col-md-6"><label class="form-label" for="match-{{ $match->id }}-move-duration">Длительность, минут</label><input class="form-control" id="match-{{ $match->id }}-move-duration" type="number" name="duration_minutes" value="{{ (int) $match->game->event->starts_at->diffInMinutes($match->game->event->ends_at) }}" min="1" max="1440" required></div>
                                <div class="col-12">@include('theme::partials.venues.predictive-selector', ['id' => 'moveMatch'.$match->id.'Venue', 'selectedVenue' => $match->game->event->venue, 'startInput' => '#match-'.$match->id.'-move-starts', 'durationInput' => '#match-'.$match->id.'-move-duration', 'confirmedOnly' => true])</div>
                                <div class="col-12"><button class="btn btn--primary" type="submit">Перенести игру и бронь</button></div>
                            </form>
                        </details>
                    @endif @endforeach
                @endif
            </div>
        @endif
    @endif

    @if($effectivePermissions->contains(\App\Modules\Tournament\Domain\Enums\TournamentPermissionEnum::DELETE))
        <div class="event-card" id="delete"><h2>Удаление</h2><form method="POST" action="{{ route('tournaments.destroy', $tournament->routeIdentifier()) }}">@csrf @method('DELETE')<button class="btn btn--danger" type="submit">Удалить турнир</button></form></div>
    @endif
@endsection
