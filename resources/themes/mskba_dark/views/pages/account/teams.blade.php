@extends('theme::layouts.section-sidebar', [
    'title' => 'Мои команды', 'sectionId' => 'account', 'sectionClass' => 'account-section',
    'contentTitle' => 'Мои команды', 'sidebarLabel' => 'Навигация аккаунта',
    'wrapSidebarPanel' => false, 'sidebarPartial' => 'theme::partials.account.sidebar',
])

@section('section-heading-action')
    @can('team-create')
        @if($canCreateTeam)
            <a href="{{ route('teams.create') }}" class="btn btn--primary btn--sm">Создать команду</a>
        @else
            <span class="d-inline-flex flex-column align-items-end gap-1">
                <button class="btn btn--primary btn--sm" type="button" disabled aria-disabled="true">Создать команду</button>
                <small class="form-hint">Достигнут лимит: можно создать не более {{ $teamCreationLimit }} команд.</small>
            </span>
        @endif
    @endcan
@endsection

@section('section-content')
<form class="account-team-filters" method="GET" action="{{ route('account.teams') }}">
    <label><span>Статус команды</span><select class="form-select" name="status">
        <option value="">Все статусы</option>
        @foreach($statuses as $status)
            <option value="{{ $status->value }}" @selected($filters['status'] === $status->value)>{{ $status->label() }}</option>
        @endforeach
    </select></label>
    <label><span>Условия участия</span><select class="form-select" name="condition">
        <option value="">Все команды</option>
        @foreach(\App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum::cases() as $status)
            <option value="{{ $status->value }}" @selected($filters['condition'] === $status->value)>{{ $status->label() }}</option>
        @endforeach
    </select></label>
    <label class="account-team-filters__toggle"><input type="checkbox" name="created_only" value="1" @checked($filters['created_only'])><span>Только созданные мной</span></label>
    <button class="btn btn--secondary btn--sm" type="submit">Применить</button>
</form>

<div class="section-list account-team-list">
@forelse($teams as $team)
    @php($membership = $team->memberships->first())
    <article class="section-list-item account-team-list__item">
        <img src="{{ $team->logo?->publicUrl() ?? asset('images/team-placeholder.webp') }}" alt="">
        <div>
            <h2 class="h5"><a href="{{ $team->status === \App\Modules\Team\Domain\Enums\TeamStatusEnum::DRAFT ? route('teams.edit', $team->routeIdentifier()) : route('teams.show', $team->routeIdentifier()) }}">{{ $team->name }}</a></h2>
            <div class="team-profile__sports">@foreach($team->sportProfiles as $profile)<span>{{ $profile->sport_type->label() }}</span>@endforeach</div>
            <p>{{ $membership?->invitation_status?->label() ?? 'Создатель команды' }}</p>
        </div>
        <div class="account-team-list__state">
            <span class="team-profile__status">{{ $team->status->label() }}</span>
            @if($team->status === \App\Modules\Team\Domain\Enums\TeamStatusEnum::ACTIVE)
                <span @class(['team-profile__status', 'is-incomplete' => ! $team->roster_complete])>{{ $team->roster_complete ? 'Состав укомплектован' : 'Неполный состав' }}</span>
            @elseif($team->status === \App\Modules\Team\Domain\Enums\TeamStatusEnum::DRAFT)
                <a class="btn btn--secondary btn--sm" href="{{ route('teams.edit', $team->routeIdentifier()) }}">Открыть настройки</a>
            @endif
            @if($membership?->invitation_status === \App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum::PENDING)
                <form method="POST" action="{{ route('teams.invitations.respond', $membership->id) }}">@csrf @method('PATCH')
                    <button class="btn btn--primary btn--sm" name="decision" value="accept">Принять</button>
                    <button class="btn btn--secondary btn--sm" name="decision" value="decline">Отклонить</button>
                </form>
            @endif
        </div>
    </article>
@empty <div class="alert alert-info">По выбранным условиям команд нет.</div>@endforelse
</div>
{{ $teams->links() }}
@endsection
