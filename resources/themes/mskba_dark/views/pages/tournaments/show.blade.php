@php
    $breadcrumbs = [
        ['label' => 'Турниры', 'url' => route('tournaments.index')],
        ['label' => $tournament->title],
    ];
    $organizer = $tournament->createdByActor?->user;
    $organizerName = trim(implode(' ', array_filter([
        $organizer?->profile?->first_name,
        $organizer?->profile?->last_name,
    ]))) ?: $organizer?->username ?: 'Организатор';
    $teamsLabel = $tournament->format?->sideSize() === 1 ? 'Участники' : 'Команды';
@endphp

@extends('theme::layouts.section-sidebar', [
    'title' => $tournament->title,
    'sectionId' => 'tournament',
    'sectionClass' => 'tournaments-section tournament-show-section',
    'contentTitle' => $tournament->title,
    'contentSubtitle' => $tournament->starts_on->format('d.m.Y').($tournament->ends_on ? ' — '.$tournament->ends_on->format('d.m.Y') : ''),
    'sidebarLabel' => 'Навигация турнира',
])

@section('section-sidebar')
    <div class="section-sidebar-block">
        <h2 class="section-sidebar-block__title">Турнир</h2>
        <ul class="sidebar-nav nav flex-column">
            <li class="nav-item"><a class="nav-link" href="#overview">Обзор</a></li>
            <li class="nav-item"><a class="nav-link" href="#teams">{{ $teamsLabel }}</a></li>
            <li class="nav-item"><a class="nav-link" href="#games">Игры</a></li>
            <li class="nav-item"><a class="nav-link" href="#table">Таблица</a></li>
        </ul>
    </div>
    @if($canManage)
        <div class="section-sidebar-block">
            <h2 class="section-sidebar-block__title">Управление</h2>
            <a class="btn btn--primary btn--sm" href="{{ route('tournaments.manage', $tournament->routeIdentifier()) }}">Управление турниром</a>
        </div>
    @endif
@endsection

@section('section-content')
    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif

    <article class="section-card tournament-show-hero mb-4">
        @if($tournament->cover)
            <img class="tournament-show-hero__cover" src="{{ $tournament->cover->publicUrl() }}" alt="Обложка турнира {{ $tournament->title }}">
        @endif
        <span class="eyebrow">Турнир · {{ $tournament->phase()->label() }}</span>
        <p><strong>{{ $tournament->status->label() }}</strong>@if($tournament->format) · {{ $tournament->format->label() }}@endif</p>
        <p class="tournament-organizer"><span>Организатор:</span> <button class="tournament-organizer__trigger" type="button" title="Действия для связи с организатором появятся здесь позже" data-tooltip-variant="title" data-tournament-organizer-trigger data-organizer-id="{{ $organizer?->id }}" data-organizer-username="{{ $organizer?->username }}">{{ $organizerName }}</button></p>
        @if($tournament->status_comment)<div class="alert alert-info">{{ $tournament->status_comment }}</div>@endif
        @if($tournament->short_description)<p>{{ $tournament->short_description }}</p>@endif
        @if($tournament->full_description)<div>{!! app(\App\Modules\Content\Application\Services\ContentBodyRenderer::class)->renderPlainText($tournament->full_description) !!}</div>@endif
        @if(session('error'))<div class="alert alert-danger mt-4">{{ session('error') }}</div>@endif

        @auth
            @if($myPendingInvitations->isNotEmpty())
                @foreach($myPendingInvitations as $pendingInvitation)
                    <div class="mt-4"><h2>Приглашение в турнир</h2>@if($pendingInvitation->team)<p>Команда: <strong>{{ $pendingInvitation->team->name }}</strong></p>@endif
                        @if(!$tournament->acceptsAdmissions())<p class="text-muted">Приём участников уже закрыт, принять приглашение больше нельзя.</p>@endif
                        <div class="d-flex gap-2">@if($tournament->acceptsAdmissions())<form method="POST" action="{{ route('tournaments.admissions.respond', [$tournament->routeIdentifier(), $pendingInvitation]) }}">@csrf<input type="hidden" name="decision" value="accepted"><button class="btn btn--primary btn--sm">Принять</button></form>@endif<form method="POST" action="{{ route('tournaments.admissions.respond', [$tournament->routeIdentifier(), $pendingInvitation]) }}">@csrf<input type="hidden" name="decision" value="declined"><button class="btn btn--secondary btn--sm">Отклонить</button></form></div>
                    </div>
                @endforeach
            @elseif($canApplyAsPlayer)
                <span data-tournament-application-cta="{{ $tournament->id }}"><button class="btn btn--primary btn--sm mt-4 js-handler" type="button" data-handler="modal" data-modal-action="open" data-modal-target="tournament-application-role">Подать заявку</button></span>
            @endif
        @else
            @if($tournament->recruitment_mode === \App\Modules\Tournament\Domain\Enums\TournamentRecruitmentModeEnum::INDIVIDUAL_DRAFT && $tournament->acceptsAdmissions())
                <span data-tournament-application-cta="{{ $tournament->id }}"><button
                    class="btn btn--primary btn--sm mt-4 js-handler"
                    type="button"
                    data-handler="modal"
                    data-modal-action="open"
                    data-modal-target="auth-entry-classic"
                    data-auth-redirect-url="{{ route('tournaments.show', $tournament->routeIdentifier(), false) }}"
                >Подать заявку</button></span>
            @endif
        @endauth
    </article>

    @auth
        @if($canApplyAsPlayer && $myPendingInvitations->isEmpty())
            @component('theme::partials.modal.layout', ['id' => 'tournament-application-role'])
                <h2 class="modal_title" id="modal-title-tournament-application-role">В качестве кого?</h2>
                <p class="modal-description">Выберите роль, в которой хотите участвовать в турнире.</p>
                <form method="POST" action="{{ route('tournaments.admissions.apply', $tournament->routeIdentifier()) }}" data-tournament-application-role-form>
                    @csrf
                    <div class="d-grid mb-4">
                        @foreach($admissionRoles as $role)
                            <label class="form-toggle">
                                <input class="form-toggle__input" type="checkbox" name="roles[]" value="{{ $role->value }}" data-tournament-application-role @checked($role === \App\Modules\Tournament\Domain\Enums\TournamentAdmissionRoleEnum::PLAYER)>
                                <span class="form-toggle__control" aria-hidden="true"></span>
                                <strong class="form-toggle__title">{{ $role->label() }}</strong>
                            </label>
                        @endforeach
                    </div>
                    <button class="btn btn--primary" type="submit">Подать заявку</button>
                </form>
            @endcomponent
        @endif
    @endauth

    <section class="section-card mb-4" id="overview"><h2>Обзор</h2><p>{{ $tournament->short_description ?: 'Описание турнира пока не добавлено.' }}</p><div class="d-flex flex-wrap gap-3"><span>{{ $tournament->recruitment_mode === \App\Modules\Tournament\Domain\Enums\TournamentRecruitmentModeEnum::PREFORMED_TEAMS ? 'Команд' : 'Участников' }}: {{ $publicParticipantCount }}</span><span>Матчей: {{ $tournament->matches->count() }}</span><span>Подтверждено результатов: {{ (int) (collect($standings)->sum('played') / 2) }}</span></div></section>

    <section class="section-card mb-4" id="teams"><h2>{{ $teamsLabel }}</h2>
        @if($tournament->entries->isNotEmpty())<div class="tournament-team-grid">@foreach($tournament->entries as $entry)
            @php($teamLogo = $entry->logoUrl())
            <article class="tournament-team-card"><img class="tournament-team-card__logo" src="{{ $teamLogo }}" alt="Логотип {{ $entry->name }}"><div>@if($entry->team)<a class="tournament-team-card__name" href="{{ route('teams.show', $entry->team->routeIdentifier()) }}">{{ $entry->name }}</a>@else<strong class="tournament-team-card__name">{{ $entry->name }}</strong>@endif<div class="text-muted">{{ $entry->effectiveMembers->count() }} в составе@if($entry->effectiveMembers->isNotEmpty()): {{ $entry->effectiveMembers->map(fn ($member) => trim(($member->profile?->first_name ?? '').' '.($member->profile?->last_name ?? '')) ?: $member->username)->join(', ') }}@endif</div></div></article>
        @endforeach</div>@else<p>Участники ещё не определены.</p>@endif
    </section>

    <section class="section-card mb-4" id="games"><h2>Игры</h2>@forelse($tournament->matches as $match)@php($game = $match->game)@php($sides = $game?->sides?->keyBy('slot'))<div class="border rounded p-3 mb-2"><div><span>#{{ $match->sequence }}</span> <strong>{{ $match->entryA->name }}</strong> <span>{{ $sides?->get('A')?->score ?? '—' }} : {{ $sides?->get('B')?->score ?? '—' }}</span> <strong>{{ $match->entryB->name }}</strong></div><div class="text-muted">{{ $game?->status?->label() ?? 'Не назначена' }}@if($game?->statistics_status === \App\Modules\Event\Domain\Enums\GameStatisticsStatusEnum::CONFIRMED) · результат подтверждён@endif @if($game?->event) · {{ $game->event->starts_at->format('d.m.Y H:i') }} · {{ $game->event->venue->name }}@if($game->event->booking?->scope) · {{ $game->event->booking->scope->label() }}@endif @endif</div>@if($game?->event)<a class="fc-link" href="{{ route('events.show', $game->event->routeIdentifier()) }}">Открыть игру</a>@endif</div>@empty<p>Матчи ещё не сформированы.</p>@endforelse</section>

    <section class="section-card" id="table"><h2>Турнирная таблица</h2><p class="text-muted mb-2">Учитываются только подтверждённые результаты. Победа — 2 очка, ничья — 1, поражение — 0.</p><div class="table-responsive"><table class="table"><thead><tr><th><span title="Текущая позиция команды в турнирной таблице" data-tooltip-variant="title" tabindex="0">#</span></th><th>Команда</th><th><span title="Сыграно игр" data-tooltip-variant="title" tabindex="0">И</span></th><th><span title="Победы" data-tooltip-variant="title" tabindex="0">В</span></th><th><span title="Ничьи" data-tooltip-variant="title" tabindex="0">Н</span></th><th><span title="Поражения" data-tooltip-variant="title" tabindex="0">П</span></th><th><span title="Забитые и пропущенные мячи" data-tooltip-variant="title" tabindex="0">Мячи</span></th><th><span title="Разница забитых и пропущенных мячей" data-tooltip-variant="title" tabindex="0">Разн.</span></th><th><span title="Турнирные очки" data-tooltip-variant="title" tabindex="0">Очки</span></th></tr></thead><tbody>@foreach($standings as $row)<tr><td>{{ $row['position'] }}</td><td>{{ $row['name'] }}</td><td>{{ $row['played'] }}</td><td>{{ $row['wins'] }}</td><td>{{ $row['draws'] }}</td><td>{{ $row['losses'] }}</td><td>{{ $row['scored'] }}:{{ $row['conceded'] }}</td><td>{{ $row['difference'] > 0 ? '+' : '' }}{{ $row['difference'] }}</td><td><strong>{{ $row['points'] }}</strong></td></tr>@endforeach</tbody></table></div></section>
@endsection
