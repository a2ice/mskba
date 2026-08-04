@extends('theme::layouts.section-sidebar', [
    'title' => 'Мои команды', 'sectionId' => 'account', 'sectionClass' => 'account-section',
    'contentTitle' => 'Мои команды', 'sidebarLabel' => 'Навигация аккаунта',
    'wrapSidebarPanel' => false, 'sidebarPartial' => 'theme::partials.account.sidebar',
])

@section('section-heading-action')
    @can('team-create')<a href="{{ route('teams.create') }}" class="btn btn--primary btn--sm">Создать команду</a>@endcan
@endsection

@section('section-content')
<form class="account-team-filters" method="GET" action="{{ route('account.teams') }}">
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
        <div><h2 class="h5"><a href="{{ route('teams.show', $team->routeIdentifier()) }}">{{ $team->name }}</a></h2>
            <div class="team-profile__sports">@foreach($team->sportProfiles as $profile)<span>{{ $profile->sport_type->label() }}</span>@endforeach</div>
            <p>{{ $membership?->invitation_status?->label() ?? 'Создатель команды' }}</p>
        </div>
        <div class="account-team-list__state">
            <span @class(['team-profile__status', 'is-incomplete' => ! $team->roster_complete])>{{ $team->roster_complete ? 'Состав укомплектован' : 'Неполный состав' }}</span>
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
