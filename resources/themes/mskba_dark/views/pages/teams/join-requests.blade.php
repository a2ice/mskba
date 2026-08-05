@extends('theme::layouts.section-sidebar', [
    'title' => 'Заявки на вступление', 'sectionId' => 'teams', 'sectionClass' => 'teams-section',
    'contentTitle' => 'Заявки на вступление', 'contentSubtitle' => $team->name,
])
@php
    $userName = static fn ($entry) => trim(implode(' ', array_filter([
        $entry->user->profile?->first_name,
        $entry->user->profile?->last_name,
    ]))) ?: $entry->user->username;
    $avatarUrl = static fn ($entry): string => $entry->user->profile?->activeAvatar?->publicUrl()
        ?? asset($entry->user->profile?->gender === \App\Modules\Identity\Domain\Enums\UserGenderEnum::FEMALE
            ? 'images/blank/avatar/avatar-female.png'
            : 'images/blank/avatar/avatar-male.png');
@endphp
@section('section-sidebar')
<div class="section-sidebar-block"><h2 class="section-sidebar-block__title">Команда</h2><ul class="sidebar-nav nav flex-column">
<li class="nav-item"><a class="nav-link" href="{{ route('teams.show', $team->routeIdentifier()) }}">Обзор</a></li>
@if($canEditSettings)<li class="nav-item"><a class="nav-link" href="{{ route('teams.edit', $team->routeIdentifier()) }}">Основные настройки</a></li>@endif
<li class="nav-item"><a class="nav-link" href="{{ route('teams.management', $team->routeIdentifier()) }}">Состав и участники</a></li>
<li class="nav-item active"><a class="nav-link active" href="{{ route('teams.join-requests.index', $team->routeIdentifier()) }}">Заявки на вступление</a></li>
</ul></div>
@endsection
@section('section-content')
@if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

<section class="team-profile__section">
    <div class="team-profile__section-heading"><i class="ti ti-user-check"></i><div><span>Новые участники</span><h2>Заявки на вступление</h2></div></div>
    <div class="section-list">
        @forelse($joinRequests as $entry)
            <article class="section-card team-join-request-card">
                <div class="team-person team-person--manager">
                    <img src="{{ $avatarUrl($entry) }}" alt="Аватар {{ $userName($entry) }}">
                    <div>
                        <strong>{{ $userName($entry) }}</strong>
                        <span>{{ '@'.$entry->user->username }} · {{ $entry->status->label() }}</span>
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2 mt-3">
                    @if($entry->status === \App\Modules\Team\Domain\Enums\TeamJoinRequestStatusEnum::PENDING)
                        <form method="POST" action="{{ route('teams.join-requests.respond', [$team->routeIdentifier(), $entry->id]) }}" onsubmit="return confirm('Вы уверены, что хотите принять заявку и добавить пользователя в команду?')">@csrf @method('PATCH')<input type="hidden" name="action" value="accept"><button class="btn btn--primary btn--sm" type="submit">Принять</button></form>
                        <form method="POST" action="{{ route('teams.join-requests.respond', [$team->routeIdentifier(), $entry->id]) }}" onsubmit="return confirm('Вы уверены, что хотите отклонить заявку?')">@csrf @method('PATCH')<input type="hidden" name="action" value="reject"><button class="btn btn--secondary btn--sm" type="submit">Отклонить</button></form>
                        <form method="POST" action="{{ route('teams.join-requests.respond', [$team->routeIdentifier(), $entry->id]) }}" onsubmit="return confirm('Заблокировать пользователя? Он не сможет отправлять новые заявки, пока его не разблокируют.')">@csrf @method('PATCH')<input type="hidden" name="action" value="block"><button class="btn btn--danger btn--sm" type="submit">Заблокировать</button></form>
                    @elseif($entry->status === \App\Modules\Team\Domain\Enums\TeamJoinRequestStatusEnum::BLOCKED)
                        <form method="POST" action="{{ route('teams.join-requests.respond', [$team->routeIdentifier(), $entry->id]) }}" onsubmit="return confirm('Разблокировать пользователя? Он снова сможет отправить заявку.')">@csrf @method('PATCH')<input type="hidden" name="action" value="unblock"><button class="btn btn--secondary btn--sm" type="submit">Разблокировать</button></form>
                    @endif
                </div>
            </article>
        @empty
            <p class="team-profile__empty">Заявок пока нет.</p>
        @endforelse
    </div>
</section>
@endsection
