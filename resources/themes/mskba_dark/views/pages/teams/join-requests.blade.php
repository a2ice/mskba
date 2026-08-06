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

                @if($entry->review_reason)
                    <div class="alert alert-secondary mt-3 mb-0"><strong>Причина решения:</strong><br>{!! nl2br(e($entry->review_reason)) !!}</div>
                @endif

                @if($entry->status === \App\Modules\Team\Domain\Enums\TeamJoinRequestStatusEnum::PENDING)
                    <form class="mt-3" method="POST" action="{{ route('teams.join-requests.respond', [$team->routeIdentifier(), $entry->id]) }}" onsubmit="return !event.submitter?.dataset.confirmMessage || confirm(event.submitter.dataset.confirmMessage)">
                        @csrf
                        @method('PATCH')
                        <label class="form-label" for="join-request-reason-{{ $entry->id }}">Причина решения</label>
                        <textarea class="form-control" id="join-request-reason-{{ $entry->id }}" name="review_reason" rows="3" maxlength="2000" placeholder="Обязательна при отклонении или блокировке">{{ old('review_reason') }}</textarea>
                        @error('review_reason', 'joinRequest'.$entry->id)<div class="form-error mt-2">{{ $message }}</div>@enderror
                        <div class="d-flex flex-wrap gap-2 mt-3">
                            <button class="btn btn--primary btn--sm" type="submit" name="action" value="accept" data-confirm-message="Вы уверены, что хотите принять заявку и добавить пользователя в команду?">Принять</button>
                            <button class="btn btn--secondary btn--sm" type="submit" name="action" value="reject" data-confirm-message="Вы уверены, что хотите отклонить заявку?">Отклонить</button>
                            <button class="btn btn--danger btn--sm" type="submit" name="action" value="block" data-confirm-message="Заблокировать пользователя? Он не сможет отправлять новые заявки, пока его не разблокируют.">Заблокировать</button>
                        </div>
                    </form>
                @else
                    <div class="d-flex flex-wrap gap-2 mt-3">
                        @if(in_array($entry->status, [\App\Modules\Team\Domain\Enums\TeamJoinRequestStatusEnum::REJECTED, \App\Modules\Team\Domain\Enums\TeamJoinRequestStatusEnum::BLOCKED], true))
                            <button class="btn btn--secondary btn--sm js-handler" type="button" data-handler="modal" data-modal-action="open" data-modal-target="team-join-request-message">Написать сообщение</button>
                        @endif
                        @if($entry->status === \App\Modules\Team\Domain\Enums\TeamJoinRequestStatusEnum::BLOCKED)
                            <form method="POST" action="{{ route('teams.join-requests.respond', [$team->routeIdentifier(), $entry->id]) }}" onsubmit="return confirm('Разблокировать пользователя? Он снова сможет отправить заявку.')">@csrf @method('PATCH')<input type="hidden" name="action" value="unblock"><button class="btn btn--secondary btn--sm" type="submit">Разблокировать</button></form>
                        @endif
                    </div>
                @endif
            </article>
        @empty
            <p class="team-profile__empty">Заявок пока нет.</p>
        @endforelse
    </div>
</section>

@component('theme::partials.modal.layout', ['id' => 'team-join-request-message'])
    <h2 class="modal_title" id="modal-title-team-join-request-message">Сообщение участнику</h2>
    <p class="modal-description">Личные сообщения по заявкам находятся в разработке.</p>
@endcomponent
@endsection
