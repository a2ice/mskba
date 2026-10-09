@extends('theme::layouts.app', ['title' => $team->name])

@section('content')
    <section class="container app-team-section app-team-public" aria-labelledby="team-profile-heading">
        <header class="app-account-overview__intro">
            <h1 id="team-profile-heading">{{ $team->name }}</h1>
            <p>{{ $team->description ?: 'Знакомься с составом и возможностями команды.' }}</p>
        </header>
        <div class="app-team-actions">
            <a class="button secondary" href="{{ route('teams.index') }}">Все команды</a>
            @auth <a class="button secondary" href="{{ route('account.teams') }}">Мои команды</a> @endauth
        </div>
        @if (session('status'))
            <p class="notice app-team-feedback" role="status">{{ session('status') }}</p>
        @endif
        @if ($errors->any())
            <div class="notice app-team-feedback" role="alert">{{ $errors->first() }}</div>
        @endif
        <article class="panel app-team-profile">
            <div class="app-team-profile__header">
                @if ($team->logo?->publicUrl())
                    <img class="app-team-card__logo" src="{{ $team->logo->publicUrl() }}" alt="">
                @endif
                <div class="app-team-card__sports">
                    <span class="badge">{{ $team->status->label() }}</span>
                    @foreach ($team->sportProfiles as $profile)
                        <span class="badge">{{ $profile->sport_type->label() }}</span>
                    @endforeach
                </div>
            </div>
            <p>Участников в составе: <strong>{{ $activeMemberships->count() }}</strong></p>
            @if ($isActiveTeamMember)
                <p class="app-team-card__detail">Ты уже состоишь в этой команде.</p>
            @elseif ($currentJoinRequest?->status === \App\Modules\Team\Domain\Enums\TeamJoinRequestStatusEnum::PENDING)
                <p class="app-team-card__detail">Твоя заявка ожидает решения команды.</p>
            @elseif ($currentJoinRequest?->status === \App\Modules\Team\Domain\Enums\TeamJoinRequestStatusEnum::BLOCKED)
                <p class="app-team-card__detail">Заявки в эту команду для тебя заблокированы.</p>
                @if ($currentJoinRequest->review_reason)
                    <p class="app-team-card__detail">{{ $currentJoinRequest->review_reason }}</p>
                @endif
            @elseif ($canApplyToTeam && $team->status === \App\Modules\Team\Domain\Enums\TeamStatusEnum::ACTIVE)
                @if ($currentJoinRequest?->status === \App\Modules\Team\Domain\Enums\TeamJoinRequestStatusEnum::REJECTED)
                    <p class="app-team-card__detail">Предыдущая заявка была отклонена. Можно отправить новую.</p>
                    @if ($currentJoinRequest->review_reason)
                        <p class="app-team-card__detail">{{ $currentJoinRequest->review_reason }}</p>
                    @endif
                @endif
                <form method="POST" action="{{ route('teams.join-requests.store', $team->routeIdentifier()) }}">
                    @csrf
                    <button class="button primary" type="submit">Подать заявку на вступление</button>
                </form>
            @elseif (auth()->guest())
                <p class="app-team-card__detail">Войди в аккаунт, чтобы подать заявку на вступление.</p>
            @else
                <p class="app-team-card__detail">Команда сейчас не принимает общие заявки.</p>
            @endif
        </article>
    </section>
@endsection
