@extends('theme::layouts.section-sidebar', [
    'title' => $team->name, 'sectionId' => 'teams', 'sectionClass' => 'teams-section',
    'contentTitle' => $team->name, 'contentSubtitle' => $team->description,
])
@php
    $memberName = static fn ($membership) => trim(implode(' ', array_filter([$membership->user->profile?->first_name, $membership->user->profile?->last_name]))) ?: $membership->user->username;
    $avatarUrl = static fn ($membership): string => $membership->user->profile?->activeAvatar?->publicUrl()
        ?? asset($membership->user->profile?->gender === \App\Modules\Identity\Domain\Enums\UserGenderEnum::FEMALE ? 'images/blank/avatar/avatar-female.png' : 'images/blank/avatar/avatar-male.png');
    $isCaptain = static fn ($membership): bool => $membership->is_captain;
@endphp
@section('section-sidebar')
<div class="section-sidebar-block"><h2 class="section-sidebar-block__title">Команда</h2><ul class="sidebar-nav nav flex-column">
<li class="nav-item active"><a class="nav-link active" href="{{ route('teams.show', $team->routeIdentifier()) }}">Обзор</a></li>
@if($canManage)<li class="nav-item"><a class="nav-link" href="{{ route('teams.edit', $team->routeIdentifier()) }}">Управление</a></li>@endif
</ul></div>
@endsection
@section('section-content')
<article class="team-profile" data-team-management>
    <div class="alert alert-danger" data-team-management-error hidden></div>
    <div class="alert alert-success" data-team-management-success hidden></div>
    <header class="team-profile__header">
        <img class="team-profile__logo" src="{{ $team->logo?->publicUrl() ?? asset('images/team-placeholder.webp') }}" alt="Логотип команды {{ $team->name }}">
        <div><div class="team-profile__header-statuses"><span class="team-profile__status">{{ $team->status->label() }}</span><span @class(['team-profile__status', 'is-incomplete' => ! $hasCompleteRoster])>{{ $hasCompleteRoster ? 'Состав укомплектован' : 'Неполный состав' }}</span></div>
            <div class="team-profile__sports">@foreach($team->sportProfiles as $profile)<span>{{ $profile->sport_type->label() }}</span>@endforeach</div>
        </div>
    </header>

    <section class="team-profile__section" aria-labelledby="team-coaches-title">
        <div class="team-profile__section-heading"><i class="ti ti-user-cog"></i><div><span>Тренерский штаб</span><h2 id="team-coaches-title">Тренер</h2></div></div>
        <div class="team-coaches">@forelse($coaches as $coach)<div class="team-person team-person--coach"><img src="{{ $avatarUrl($coach) }}" alt=""><div><strong>{{ $memberName($coach) }}</strong><span>Тренер команды</span></div></div>@empty<p class="team-profile__empty">Тренер пока не назначен.</p>@endforelse</div>
    </section>

    @if($activeMemberships->isNotEmpty() && ($canManagePermissions || $canRemoveMembers))
    <section class="team-profile__section" aria-labelledby="team-managers-title">
        <div class="team-profile__section-heading"><i class="ti ti-shield-cog"></i><div><span>Договорные условия</span><h2 id="team-managers-title">Права участников</h2></div></div>
        <div class="team-managers">@foreach($activeMemberships as $member)
            <form class="team-manager-contract" data-team-permissions-form data-update-url="{{ route('teams.members.permissions', [$team->routeIdentifier(), $member->id]) }}">
                <div class="team-person team-person--manager"><img src="{{ $avatarUrl($member) }}" alt=""><div><strong>{{ $memberName($member) }}</strong><span>{{ $member->access_level === 'owner' ? 'Создатель' : $member->member_type->label() }}</span></div></div>
                <div class="team-invitation__permissions">@foreach($teamPermissions as $permission)<label><input type="checkbox" name="permissions[]" value="{{ $permission->value }}" @checked($member->access_level === 'owner' || $member->contract->permissions->contains('permission', $permission->value)) @disabled(!$canManagePermissions || $member->access_level === 'owner')><span>{{ $permission->label() }}</span></label>@endforeach</div>
                <div class="team-manager-contract__actions">
                    @if($canManagePermissions && $member->access_level !== 'owner')<button class="btn btn--secondary btn--sm" type="submit">Сохранить права</button>@endif
                    @if($canRemoveMembers && $member->access_level !== 'owner' && $member->user_id !== $currentUserId)<button class="btn btn--danger btn--sm" type="button" data-team-member-remove-url="{{ route('teams.members.destroy', [$team->routeIdentifier(), $member->id]) }}">Исключить</button>@endif
                </div>
                <div class="team-form-feedback" data-team-form-feedback hidden></div>
            </form>
        @endforeach</div>
    </section>
    @endif

    <section class="team-profile__section" aria-labelledby="team-lineups-title">
        <div class="team-profile__section-heading"><i class="ti ti-layout-grid"></i><div><span>Игровые группы</span><h2 id="team-lineups-title">Составы по дисциплинам</h2></div></div>
        <div class="team-sport-groups">
        @foreach($startingLineups as $lineup)
            <section class="team-sport-group" data-team-roster data-update-url="{{ route('teams.roster.update', $team->routeIdentifier()) }}" data-sport-type="{{ $lineup['sport_type'] }}" data-limit="{{ $lineup['size'] }}" data-editable="{{ $canManageRoster ? '1' : '0' }}">
                <header><div><strong>{{ $lineup['label'] }}</strong><span>Основа: <b data-starter-count>{{ $lineup['starters']->count() }}</b> / {{ $lineup['size'] }}</span></div>@if($canManageRoster)<button class="btn btn--primary btn--sm" type="button" data-roster-save>Сохранить</button>@endif</header>
                <div class="team-form-feedback" data-team-form-feedback hidden></div>
                <div class="team-roster-pool"><div class="team-roster-pool__heading"><span>Основной состав</span><b>{{ $lineup['size'] }} мест</b></div><div class="team-roster-dropzone" data-roster-zone="starter">
                    @foreach($lineup['starters'] as $player) @include('theme::pages.teams.partials.roster-player', compact('player', 'memberName', 'avatarUrl', 'isCaptain', 'canManageRoster', 'canManageRoles', 'team')) @endforeach
                    @if($lineup['starters']->isEmpty())<p class="team-roster-dropzone__empty">Перенесите сюда основных игроков</p>@endif
                </div></div>
                <div class="team-roster-pool team-roster-pool--reserve"><div class="team-roster-pool__heading"><span>Запасные</span><b><span data-reserve-count>{{ $lineup['reserves']->count() }}</span> игроков</b></div><div class="team-roster-dropzone" data-roster-zone="reserve">
                    @foreach($lineup['reserves'] as $player) @include('theme::pages.teams.partials.roster-player', compact('player', 'memberName', 'avatarUrl', 'isCaptain', 'canManageRoster', 'canManageRoles', 'team')) @endforeach
                    @if($lineup['reserves']->isEmpty())<p class="team-roster-dropzone__empty">Запас пока пуст</p>@endif
                </div></div>
            </section>
        @endforeach
        </div>
    </section>

    @if($canInviteMembers)
    <section class="team-profile__section team-invitation" data-team-invitation data-search-url="{{ route('teams.invitations.search', $team->routeIdentifier()) }}" data-store-url="{{ route('teams.invitations.store', $team->routeIdentifier()) }}">
        <div class="team-profile__section-heading"><i class="ti ti-user-plus"></i><div><span>Договорное членство</span><h2>Пригласить в команду</h2></div></div>
        <form data-team-invitation-form><div class="team-invitation__fields">
            <label class="team-invitation__search"><span>Пользователь</span><input class="form-control" type="search" autocomplete="off" placeholder="Логин или имя" data-team-user-search><input type="hidden" name="user_id" data-team-user-id><div class="team-invitation__results" data-team-user-results hidden></div></label>
            <label><span>Роль</span><select class="form-select" name="member_type"><option value="player">Игрок</option><option value="coach">Тренер</option><option value="manager">Менеджер</option></select></label>
        </div>@if($canManagePermissions)<fieldset><legend>Права по договору</legend><div class="team-invitation__permissions">@foreach($teamPermissions as $permission)<label><input type="checkbox" name="permissions[]" value="{{ $permission->value }}"><span>{{ $permission->label() }}</span></label>@endforeach</div></fieldset>@endif
        <button class="btn btn--primary" type="submit">Отправить приглашение</button></form>
        <div class="team-form-feedback" data-team-form-feedback hidden></div>
    </section>
    @endif
</article>
@endsection
