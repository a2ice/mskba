@extends('theme::layouts.section-sidebar', [
    'title' => $team->name, 'sectionId' => 'teams', 'sectionClass' => 'teams-section',
    'contentTitle' => $team->name, 'contentSubtitle' => $team->description,
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
    $roleText = static fn ($membership): string => $membership->sportRoles()
        ->map(fn ($role) => $role->label())->join(', ') ?: 'Без спортивной роли';
    $canManageRoster = false;
    $canManageRoles = false;
    $canManagePermissions = false;
    $canRemoveMembers = false;
@endphp
@section('section-sidebar')
<div class="section-sidebar-block"><h2 class="section-sidebar-block__title">Команда</h2><ul class="sidebar-nav nav flex-column">
<li class="nav-item active"><a class="nav-link active" href="{{ route('teams.show', $team->routeIdentifier()) }}">Обзор</a></li>
@if($canManage)
<li class="nav-item"><a class="nav-link" href="{{ route('teams.edit', $team->routeIdentifier()) }}">Основные настройки</a></li>
<li class="nav-item"><a class="nav-link" href="{{ route('teams.management', $team->routeIdentifier()) }}">Состав и участники</a></li>
@endif
</ul></div>
@endsection
@section('section-content')
<article class="team-profile">
    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

    <header class="team-profile__header">
        <img class="team-profile__logo" src="{{ $team->logo?->publicUrl() ?? asset('images/team-placeholder.webp') }}" alt="Логотип команды {{ $team->name }}">
        <div>
            <div class="team-profile__header-statuses">
                <span class="team-profile__status">{{ $team->status->label() }}</span>
                <span @class(['team-profile__status', 'is-incomplete' => ! $hasCompleteRoster])>{{ $hasCompleteRoster ? 'Состав укомплектован' : 'Неполный состав' }}</span>
            </div>
            <div class="team-profile__sports">@foreach($team->sportProfiles as $profile)<span>{{ $profile->sport_type->label() }}</span>@endforeach</div>
        </div>
    </header>

    <section class="team-profile__section" aria-labelledby="team-coaches-title">
        <div class="team-profile__section-heading"><i class="ti ti-user-cog"></i><div><span>Тренерский штаб</span><h2 id="team-coaches-title">Тренеры</h2></div></div>
        <div class="team-coaches">
            @forelse($coaches as $coach)
                <div class="team-person team-person--coach"><img src="{{ $avatarUrl($coach) }}" alt=""><div><strong>{{ $memberName($coach) }}</strong><span>{{ $roleText($coach) }}</span></div></div>
            @empty
                <p class="team-profile__empty">Тренер пока не назначен.</p>
            @endforelse
        </div>
    </section>

    <section class="team-profile__section" aria-labelledby="team-managers-title">
        <div class="team-profile__section-heading"><i class="ti ti-briefcase"></i><div><span>Организация команды</span><h2 id="team-managers-title">Менеджеры</h2></div></div>
        <div class="team-coaches">
            @forelse($managers as $manager)
                <div class="team-person team-person--coach"><img src="{{ $avatarUrl($manager) }}" alt=""><div><strong>{{ $memberName($manager) }}</strong><span>{{ $roleText($manager) }}</span></div></div>
            @empty
                <p class="team-profile__empty">Менеджерская роль пока никому не назначена.</p>
            @endforelse
        </div>
    </section>

    <section class="team-profile__section" aria-labelledby="team-lineups-title">
        <div class="team-profile__section-heading"><i class="ti ti-layout-grid"></i><div><span>Игровые группы</span><h2 id="team-lineups-title">Составы по дисциплинам</h2></div></div>
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