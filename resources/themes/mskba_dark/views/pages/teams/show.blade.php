@extends('theme::layouts.section-sidebar', [
    'title' => $team->name, 'sectionId' => 'teams', 'sectionClass' => 'teams-section',
    'contentTitle' => $team->name, 'contentSubtitle' => null,
])
@php
    $memberName = static fn ($membership) => trim(implode(' ', array_filter([
        $membership->user->profile?->first_name,
        $membership->user->profile?->last_name,
    ]))) ?: $membership->user->username;
    $avatarUrl = static fn ($membership): string => $membership->user->profile?->activeAvatar?->publicUrl()
        ?? asset($membership->user->profile?->gender === \App\Modules\Identity\Domain\Enums\UserGenderEnum::FEMALE
            ? 'images/blank/avatar/avatar-female.png'
            : 'images/blank/avatar/avatar-male.png');
    $isCaptain = static fn ($membership): bool => (bool) $membership->is_captain;
    $memberCountText = static function (int $count): string {
        $modulo100 = $count % 100;
        $modulo10 = $count % 10;
        $label = $modulo100 >= 11 && $modulo100 <= 14
            ? 'участников'
            : match ($modulo10) { 1 => 'участник', 2, 3, 4 => 'участника', default => 'участников' };

        return "{$count} {$label}";
    };
    $canManageRoster = false;
    $canManageRoles = false;
    $canManagePermissions = false;
    $canRemoveMembers = false;
    $teamStatusIcon = match ($team->status) {
        \App\Modules\Team\Domain\Enums\TeamStatusEnum::ACTIVE => 'ti-circle-check',
        \App\Modules\Team\Domain\Enums\TeamStatusEnum::DRAFT => 'ti-pencil',
        \App\Modules\Team\Domain\Enums\TeamStatusEnum::BLOCKED => 'ti-lock',
        \App\Modules\Team\Domain\Enums\TeamStatusEnum::ARCHIVED => 'ti-archive',
    };
    $headerCoach = $coaches->first();
    $headerCaptain = $activeMemberships->first(fn ($membership) => $membership->is_captain);
@endphp
@section('section-sidebar')
<div class="section-sidebar-block"><h2 class="section-sidebar-block__title">Команда</h2><ul class="sidebar-nav nav flex-column">
<li class="nav-item active"><a class="nav-link active" href="{{ route('teams.show', $team->routeIdentifier()) }}">Обзор</a></li>
@if($canEditSettings)<li class="nav-item"><a class="nav-link" href="{{ route('teams.edit', $team->routeIdentifier()) }}">Основные настройки</a></li>@endif
@if($canManage)<li class="nav-item"><a class="nav-link" href="{{ route('teams.management', $team->routeIdentifier()) }}">Состав и участники</a></li>@endif
@if($canManageJoinRequests)<li class="nav-item"><a class="nav-link" href="{{ route('teams.join-requests.index', $team->routeIdentifier()) }}">Заявки на вступление</a></li>@endif
</ul></div>
@endsection
@section('section-content')
<article class="team-profile">
    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

    <header class="team-profile__header team-profile__header--overview">
        <img class="team-profile__logo" src="{{ $team->logo?->publicUrl() ?? asset('images/team-placeholder.webp') }}" alt="Логотип команды {{ $team->name }}">
        <div class="team-profile__header-content">
            <div class="team-profile__header-statuses">
                <span class="team-profile__status team-status-badge" title="{{ $team->status->label() }}" data-tooltip-variant="title" data-tooltip-icon aria-label="{{ $team->status->label() }}">
                    <span class="team-status-badge__label">{{ $team->status->label() }}</span>
                    <i class="ti {{ $teamStatusIcon }} team-status-badge__icon" aria-hidden="true"></i>
                </span>
                @foreach($team->sportProfiles as $profile)
                    <span class="team-profile__sport" title="{{ $profile->sport_type->label() }}" data-tooltip-variant="title" data-tooltip-icon aria-label="{{ $profile->sport_type->label() }}">
                        <span class="team-profile__sport-full">{{ $profile->sport_type->label() }}</span>
                        <span class="team-profile__sport-short">{{ $profile->sport_type->shortLabel() }}</span>
                    </span>
                @endforeach
                @php($rosterStatusLabel = $hasCompleteRoster ? 'Состав укомплектован' : 'Неполный состав')
                <span @class(['team-profile__status', 'team-status-badge', 'is-incomplete' => ! $hasCompleteRoster]) title="{{ $rosterStatusLabel }}" data-tooltip-variant="title" data-tooltip-icon aria-label="{{ $rosterStatusLabel }}">
                    <span class="team-status-badge__label">{{ $rosterStatusLabel }}</span>
                    <i @class(['ti', 'team-status-badge__icon', 'ti-users-group' => $hasCompleteRoster, 'ti-alert-triangle' => ! $hasCompleteRoster]) aria-hidden="true"></i>
                </span>
            </div>
            <p class="team-profile__description">{{ $team->description ?: 'Описание команды пока не добавлено.' }}</p>
            <div class="team-profile__meta">
                <p title="{{ $memberCountText($activeMemberships->count()) }}" data-tooltip-variant="title" data-tooltip-icon aria-label="{{ $memberCountText($activeMemberships->count()) }}"><i class="ti ti-users" aria-hidden="true"></i><span class="team-profile__member-count">{{ $activeMemberships->count() }}</span><span class="team-profile__member-label"> {{ str($memberCountText($activeMemberships->count()))->after(' ') }}</span></p>
                @if($headerCoach)
                    <p title="Тренер: {{ $memberName($headerCoach) }}" data-tooltip-variant="title" data-tooltip-icon aria-label="Тренер: {{ $memberName($headerCoach) }}"><i class="ti ti-user-cog" aria-hidden="true"></i><span>Тренер: {{ $memberName($headerCoach) }}</span></p>
                @endif
                @if($headerCaptain)
                    <p title="Капитан: {{ $memberName($headerCaptain) }}" data-tooltip-variant="title" data-tooltip-icon aria-label="Капитан: {{ $memberName($headerCaptain) }}"><i class="ti ti-star" aria-hidden="true"></i><span>Капитан: {{ $memberName($headerCaptain) }}</span></p>
                @endif
            </div>
        </div>
    </header>

    @auth
        @if(!$isActiveTeamMember)
            <section class="team-profile__section" aria-labelledby="team-join-title">
                <div class="team-profile__section-heading"><i class="ti ti-user-plus"></i><div><span>Участие</span><h2 id="team-join-title">Вступление в команду</h2></div></div>
                @if($currentJoinRequest?->status === \App\Modules\Team\Domain\Enums\TeamJoinRequestStatusEnum::PENDING)
                    <p class="form-hint">Ваша заявка отправлена и ожидает решения.</p>
                @elseif($currentJoinRequest?->status === \App\Modules\Team\Domain\Enums\TeamJoinRequestStatusEnum::BLOCKED)
                    <p class="form-hint">Отправка заявок в эту команду для вас заблокирована.</p>
                @elseif($team->accepts_join_requests && $canApplyToTeam)
                    @if($currentJoinRequest?->status === \App\Modules\Team\Domain\Enums\TeamJoinRequestStatusEnum::REJECTED)
                        <p class="form-hint mb-3">Предыдущая заявка была отклонена. Вы можете отправить новую.</p>
                    @endif
                    <form method="POST" action="{{ route('teams.join-requests.store', $team->routeIdentifier()) }}" onsubmit="return confirm('Отправить заявку на вступление в команду «{{ addslashes($team->name) }}»?')">
                        @csrf
                        <button class="btn btn--primary" type="submit">Подать заявку</button>
                    </form>
                @else
                    <p class="form-hint">Команда сейчас не принимает заявки на вступление.</p>
                @endif
            </section>
        @endif
    @endauth

    <section class="team-profile__section" aria-labelledby="team-coaches-title">
        <div class="team-profile__section-heading"><i class="ti ti-user-cog"></i><div><h2 id="team-coaches-title">Тренеры</h2></div></div>
        <div class="team-coaches">
            @forelse($coaches as $coach)
                <div class="team-person team-person--coach"><img src="{{ $avatarUrl($coach) }}" alt=""><div><strong>{{ $memberName($coach) }}</strong><span class="team-person__roles">@foreach($coach->sportRoles() as $role)<span @class(['is-current' => $role === \App\Modules\Team\Domain\Enums\TeamMemberTypeEnum::COACH])>{{ $role->label() }}</span>@endforeach</span></div></div>
            @empty
                <p class="team-profile__empty">Тренер пока не назначен.</p>
            @endforelse
        </div>
    </section>

    <section class="team-profile__section" aria-labelledby="team-managers-title">
        <div class="team-profile__section-heading"><i class="ti ti-briefcase"></i><div><h2 id="team-managers-title">Менеджеры</h2></div></div>
        <div class="team-coaches">
            @forelse($managers as $manager)
                <div class="team-person team-person--coach"><img src="{{ $avatarUrl($manager) }}" alt=""><div><strong>{{ $memberName($manager) }}</strong><span class="team-person__roles">@foreach($manager->sportRoles() as $role)<span @class(['is-current' => $role === \App\Modules\Team\Domain\Enums\TeamMemberTypeEnum::MANAGER])>{{ $role->label() }}</span>@endforeach</span></div></div>
            @empty
                <p class="team-profile__empty">Менеджерская роль пока никому не назначена.</p>
            @endforelse
        </div>
    </section>

    <section class="team-profile__section" aria-labelledby="team-lineups-title">
        <div class="team-profile__section-heading"><i class="ti ti-layout-grid"></i><div><h2 id="team-lineups-title">Состав команды</h2></div></div>
        <div class="team-sport-groups">
        @foreach($startingLineups as $lineup)
            <section class="team-sport-group" data-team-roster data-sport-type="{{ $lineup['sport_type'] }}" data-limit="{{ $lineup['size'] }}" data-editable="0">
                <header><div><strong>{{ $lineup['label'] }}</strong><span>{{ $lineup['starters']->count() }}/{{ $lineup['size'] }}</span></div></header>
                <div class="team-roster-pool"><div class="team-roster-pool__heading"><span>Основной состав</span><b>{{ $lineup['size'] }} мест</b></div><div class="team-roster-dropzone" data-roster-zone="starter">
                    @foreach($lineup['starters'] as $player)
                        @include('theme::pages.teams.partials.roster-player', compact('player', 'memberName', 'avatarUrl', 'isCaptain', 'canManageRoster', 'canManageRoles', 'canManagePermissions', 'canRemoveMembers', 'currentUserId', 'team'))
                    @endforeach
                    @if($lineup['starters']->isEmpty())<p class="team-roster-dropzone__empty">Основной состав пока не указан.</p>@endif
                </div></div>
                <div class="team-roster-pool team-roster-pool--reserve"><div class="team-roster-pool__heading"><span>Запасные</span><b>{{ $lineup['reserves']->count() }} игроков</b></div><div class="team-roster-dropzone" data-roster-zone="reserve">
                    @foreach($lineup['reserves'] as $player)
                        @include('theme::pages.teams.partials.roster-player', compact('player', 'memberName', 'avatarUrl', 'isCaptain', 'canManageRoster', 'canManageRoles', 'canManagePermissions', 'canRemoveMembers', 'currentUserId', 'team'))
                    @endforeach
                    @if($lineup['reserves']->isEmpty())<p class="team-roster-dropzone__empty">Запас пока пуст.</p>@endif
                </div></div>
            </section>
        @endforeach
        </div>
    </section>
</article>
@endsection
