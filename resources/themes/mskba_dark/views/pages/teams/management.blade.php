@extends('theme::layouts.section-sidebar', [
    'title' => 'Состав и участники', 'sectionId' => 'teams', 'sectionClass' => 'teams-section',
    'contentTitle' => 'Состав и участники', 'contentSubtitle' => $team->name,
])
@php
    $sportRoleCases = \App\Modules\Team\Domain\Enums\TeamMemberTypeEnum::cases();
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
    $canEditSportRoles = static fn ($membership): bool => $canManageRoles
        && ($membership->access_level !== 'owner'
            || $membership->user_id === $currentUserId
            || $currentUserIsAdmin);
@endphp
@section('section-sidebar')
<div class="section-sidebar-block"><h2 class="section-sidebar-block__title">Команда</h2><ul class="sidebar-nav nav flex-column">
<li class="nav-item"><a class="nav-link" href="{{ route('teams.show', $team->routeIdentifier()) }}">Обзор</a></li>
<li class="nav-item"><a class="nav-link" href="{{ route('teams.edit', $team->routeIdentifier()) }}">Основные настройки</a></li>
<li class="nav-item active"><a class="nav-link active" href="{{ route('teams.management', $team->routeIdentifier()) }}">Состав и участники</a></li>
</ul></div>
@endsection
@section('section-content')
<article class="team-profile" data-team-management>
    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
    <div class="alert alert-danger" data-team-management-error hidden></div>
    <div class="alert alert-success" data-team-management-success hidden></div>

    @if($canManageRoles)
    <section class="team-profile__section" aria-labelledby="team-sport-roles-title">
        <div class="team-profile__section-heading"><i class="ti ti-users-cog"></i><div><span>Участие в команде</span><h2 id="team-sport-roles-title">Спортивные роли участников</h2></div></div>
        <p class="form-hint">Спортивные роли не изменяют договорные права. Роли владельца может менять только сам владелец.</p>
        <div class="team-sport-role-list">
            @foreach($activeMemberships as $member)
                @if($canEditSportRoles($member))
                <form id="team-sport-role-{{ $member->id }}" class="section-card mb-3" method="POST" action="{{ route('teams.members.sports.update', [$team->routeIdentifier(), $member->id]) }}">
                    @csrf @method('PUT')
                    <div class="team-person team-person--manager"><img src="{{ $avatarUrl($member) }}" alt=""><div><strong>{{ $memberName($member) }}</strong><span>{{ $member->access_level === 'owner' ? 'Владелец команды' : $roleText($member) }}</span></div></div>
                    <fieldset class="mt-3"><legend class="form-label">Спортивные роли</legend><div class="d-flex flex-wrap gap-3">
                        @foreach($sportRoleCases as $role)
                        <label class="form-check"><input class="form-check-input" type="checkbox" name="sport_roles[]" value="{{ $role->value }}" @checked($member->hasSportRole($role))><span class="form-check-label">{{ $role->label() }}</span></label>
                        @endforeach
                    </div></fieldset>
                    <div class="d-flex flex-wrap gap-3 mt-3">
                        <label class="form-check"><input class="form-check-input" type="checkbox" name="is_captain" value="1" @checked($member->is_captain)><span class="form-check-label">Капитан</span></label>
                        <label class="form-check"><input class="form-check-input" type="checkbox" name="is_default_starter" value="1" @checked($member->is_default_starter)><span class="form-check-label">Стартовый по умолчанию</span></label>
                    </div>
                    <p class="form-hint mt-2">Капитан и стартовый участник должны иметь роль «Игрок».</p>
                    <button class="btn btn--primary btn--sm" type="submit">Сохранить роли</button>
                </form>
                @else
                <div id="team-sport-role-{{ $member->id }}" class="section-card mb-3">
                    <div class="team-person team-person--manager"><img src="{{ $avatarUrl($member) }}" alt=""><div><strong>{{ $memberName($member) }}</strong><span>Владелец команды · {{ $roleText($member) }}</span></div></div>
                    <p class="form-hint mt-2">Изменять спортивные роли владельца может только сам владелец.</p>
                </div>
                @endif
            @endforeach
        </div>
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
            <label><span>Начальная роль</span><select class="form-select" name="member_type"><option value="player">Игрок</option><option value="coach">Тренер</option><option value="manager">Менеджер</option></select></label>
        </div>@if($canManagePermissions)<fieldset><legend>Права по договору</legend><div class="team-invitation__permissions">@foreach($teamPermissions as $permission)<label><input type="checkbox" name="permissions[]" value="{{ $permission->value }}"><span>{{ $permission->label() }}</span></label>@endforeach</div></fieldset>@endif
        <button class="btn btn--primary" type="submit">Отправить приглашение</button></form>
        <div class="team-form-feedback" data-team-form-feedback hidden></div>
    </section>
    @endif

    @foreach($activeMemberships as $member)
        @if($member->access_level !== 'owner' && ($canManagePermissions || ($canRemoveMembers && $member->user_id !== $currentUserId)))
            @component('theme::partials.modal.layout', ['id' => 'team-member-permissions-'.$member->id, 'dialogClass' => 'team-permissions-modal__dialog'])
                <form class="team-permissions-modal__form" data-team-permissions-form data-update-url="{{ route('teams.members.permissions', [$team->routeIdentifier(), $member->id]) }}">
                    <h2 class="modal_title" id="modal-title-team-member-permissions-{{ $member->id }}">Права участника</h2>
                    <div class="team-person team-person--manager"><img src="{{ $avatarUrl($member) }}" alt=""><div><strong>{{ $memberName($member) }}</strong><span>{{ $roleText($member) }}</span></div></div>
                    @if($canManagePermissions)<fieldset><legend>Договорные права</legend><div class="team-invitation__permissions">@foreach($teamPermissions as $permission)
                        @include('theme::partials.forms.toggle', [
                            'id' => 'team-member-'.$member->id.'-'.str_replace('.', '-', $permission->value),
                            'name' => 'permissions[]',
                            'value' => $permission->value,
                            'title' => $permission->label(),
                            'checked' => $member->contract->permissions->contains('permission', $permission->value),
                            'includeHiddenInput' => false,
                            'wrapperClass' => 'team-permissions-modal__permission',
                        ])
                    @endforeach</div></fieldset>@endif
                    <div class="team-permissions-modal__actions">
                        @if($canManagePermissions)<button class="btn btn--primary btn--sm" type="submit">Сохранить права</button>@endif
                        @if($canRemoveMembers && $member->user_id !== $currentUserId && !$member->is_captain)<button class="btn btn--danger btn--sm" type="button" data-team-member-remove-url="{{ route('teams.members.destroy', [$team->routeIdentifier(), $member->id]) }}">Исключить из команды</button>@endif
                    </div>
                    <div class="team-form-feedback" data-team-form-feedback hidden></div>
                </form>
            @endcomponent
        @endif
    @endforeach
</article>
@endsection
