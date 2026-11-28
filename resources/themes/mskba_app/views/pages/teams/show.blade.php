@php
    $title = $team->name;
    $breadcrumbs = [['label' => 'Участники', 'url' => route('participants.index')], ['label' => 'Команды', 'url' => route('teams.index')], ['label' => $team->name]];
    $memberType = \App\Modules\Team\Domain\Enums\TeamMemberTypeEnum::class;
    $joinStatus = \App\Modules\Team\Domain\Enums\TeamJoinRequestStatusEnum::class;
    $leadership = $activeMemberships->filter(fn ($m) => $m->is_captain || $m->hasSportRole($memberType::COACH) || $m->hasSportRole($memberType::MANAGER));
    $roleLabel = static fn ($m) => collect([
        $m->is_captain ? 'Капитан' : null,
        $m->hasSportRole($memberType::MANAGER) ? 'Менеджер' : null,
        $m->hasSportRole($memberType::COACH) ? 'Тренер' : null,
    ])->filter()->implode(' · ');
    $contactPeople = $leadership->filter(fn ($m) => ($m->is_captain || $m->hasSportRole($memberType::MANAGER)) && !empty($publicMembers->get($m->id)['recipient_id']))
        ->groupBy(fn ($m) => $publicMembers->get($m->id)['recipient_id'])
        ->map(fn ($members) => ['person' => $publicMembers->get($members->first()->id), 'label' => $members->map($roleLabel)->unique()->implode(' · ')])->values();
    $hiringPositions = $team->hiringPositions;
    $publicVenues = $team->venueRelations->filter(fn ($relation) => $relation->venue?->is_published);
    $canSubmit = $canApplyToTeam && $team->status === \App\Modules\Team\Domain\Enums\TeamStatusEnum::ACTIVE
        && !in_array($currentJoinRequest?->status, [$joinStatus::PENDING, $joinStatus::AWAITING_RESPONSE, $joinStatus::ACCEPTED, $joinStatus::BLOCKED], true);
@endphp
@extends('theme::layouts.app', ['title' => $title, 'breadcrumbs' => $breadcrumbs])

@section('content')
<section class="container app-category app-team-section app-team-public app-community-detail" aria-labelledby="team-profile-heading">
    <div class="app-category__layout">
        <aside class="app-category__sidebar panel" aria-label="Разделы участников">
            <h2>Участники</h2>
            @include('theme::pages.participants.partials.navigation', ['activeSection' => 'teams.index'])
        </aside>
        <div class="app-category__main app-community-detail__main">
            @include('theme::pages.participants.partials.mobile-navigation', ['activeSection' => 'teams.index'])
            <article class="panel app-community-panel">
                @include('components.entity-public-banner', ['bannerUrl' => $team->bannerUrl()])
                <header class="app-community-hero">
                    @include('theme::pages.teams.partials.public-logo')
                    <div class="app-community-card-content">
                        <div class="app-entity-heading">
                            <h1 id="team-profile-heading">{{ $team->name }}</h1>
                            @include('theme::partials.entity-edit-link', ['url' => !empty($canEditSettings) ? route('teams.edit', $team->routeIdentifier()) : null])
                        </div>
                        <div class="app-team-card__sports">
                            @foreach($team->sportProfiles as $profile)
                                <span class="badge">{{ $profile->sport_type->label() }}</span>
                            @endforeach
                            @if($hiringPositions->isNotEmpty())<span class="badge success">Идёт набор</span>@endif
                        </div>
                        <p class="muted">Участников: {{ $activeMemberships->count() }}@if($hiringPositions->isNotEmpty()) · Свободных мест по вакансиям: {{ $hiringPositions->sum(fn ($vacancy) => $vacancy->remainingSpots()) }}@endif</p>
                    </div>
                </header>
                @if(filled($team->description))<p>{{ $team->description }}</p>@endif
                <div class="app-team-actions">
                    @if($canSubmit)
                        <a class="button primary" href="#team-join">Подать заявку на вступление</a>
                    @elseif(auth()->guest() && $team->accepts_join_requests && $team->status === \App\Modules\Team\Domain\Enums\TeamStatusEnum::ACTIVE)
                        <a class="button primary" href="{{ route('login') }}" data-auth-trigger>Вступить в команду</a>
                    @endif
                    @if($contactPeople->count() === 1)
                        @php($contact = $contactPeople->first())
                        <button class="button secondary" type="button" data-direct-message-open data-recipient-id="{{ $contact['person']['recipient_id'] }}" data-recipient-name="{{ $contact['person']['name'] }}">Связаться</button>
                    @elseif($contactPeople->count() > 1)
                        <button class="button secondary" id="team-contact-trigger" type="button" data-community-contact-open>Связаться</button>
                    @endif
                    @if(!empty($canManageUniform) || (!empty($isActiveTeamMember) && !empty($teamHasUniform)))
                        <button type="button" class="button secondary" data-mskba-open-workshop data-mskba-workshop-context="team" data-mskba-workshop-team="{{ $team->id }}">{{ !empty($canManageUniform) ? 'Настроить форму команды' : 'Посмотреть форму команды' }}</button>
                    @endif
                    @auth <a class="button secondary" href="{{ route('account.teams') }}">Мои команды</a> @endauth
                </div>
            </article>
            @if(session('status'))<p class="notice app-team-feedback" role="status">{{ session('status') }}</p>@endif
            @if($errors->any())<div class="notice app-team-feedback" role="alert">{{ $errors->first() }}</div>@endif
            @if($leadership->isNotEmpty())
                <section class="panel app-community-panel" id="team-leadership">
                    <h2>Руководство команды</h2>
                    @foreach($leadership as $membership)
                        @include('theme::pages.teams.partials.public-person', ['membership' => $membership, 'personLabel' => $roleLabel($membership), 'allowContact' => true])
                    @endforeach
                </section>
            @endif
            @if($startingLineups->isNotEmpty())
                <section class="panel app-community-panel" id="team-roster">
                    <h2>Состав команды</h2>
                    @foreach($startingLineups as $lineup)
                        <div class="app-community-lineup">
                            <h3>{{ $lineup['label'] }}</h3>
                            <p class="muted">Основной состав: {{ $lineup['starters']->count() }}/{{ $lineup['size'] }}</p>
                            @foreach($lineup['starters'] as $membership)
                                @include('theme::pages.teams.partials.public-person', ['membership' => $membership, 'personLabel' => $membership->is_captain ? 'Капитан' : null, 'allowContact' => false])
                            @endforeach
                            @if($lineup['reserves']->isNotEmpty())
                                <p class="muted">Запасные: {{ $lineup['reserves']->count() }}</p>
                                @foreach($lineup['reserves'] as $membership)
                                    @include('theme::pages.teams.partials.public-person', ['membership' => $membership, 'personLabel' => $membership->is_captain ? 'Капитан' : null, 'allowContact' => false])
                                @endforeach
                            @endif
                        </div>
                    @endforeach
                </section>
            @endif
            @if($hiringPositions->isNotEmpty())
                <section class="panel app-community-panel" id="team-hiring">
                    <h2>Набор игроков</h2>
                    @foreach($hiringPositions as $vacancy)
                        <div class="app-community-lineup">
                            <h3>Свободных мест: {{ $vacancy->remainingSpots() }}</h3>
                            @if($vacancy->playerPositions()->isNotEmpty())<p>Амплуа: {{ $vacancy->playerPositions()->map->label()->join(', ') }}</p>@endif
                            @if($vacancy->minimum_experience_years !== null)<p>Опыт: от {{ $vacancy->minimum_experience_years }} лет</p>@endif
                            @if($vacancy->gender !== null)<p>Пол: {{ $vacancy->gender->label() }}</p>@endif
                            @if(filled($vacancy->description))<p>{{ $vacancy->description }}</p>@endif
                            @if($canSubmit)
                                <form method="POST" action="{{ route('teams.join-requests.store', $team->routeIdentifier()) }}">
                                    @csrf
                                    <input type="hidden" name="team_hiring_position_id" value="{{ $vacancy->id }}">
                                    <button class="button secondary" type="submit">Откликнуться</button>
                                </form>
                            @elseif(auth()->guest() && $team->accepts_join_requests)
                                <a class="button secondary" href="{{ route('login') }}" data-auth-trigger>Войти, чтобы откликнуться</a>
                            @endif
                        </div>
                    @endforeach
                </section>
            @endif
            @if($publicVenues->isNotEmpty())
                <section class="panel app-community-panel" id="team-venues">
                    <h2>Где хотим играть</h2>
                    <ul class="app-community-list">
                        @foreach($publicVenues as $relation)
                            <li><a href="{{ route('venues.show', $relation->venue) }}">{{ $relation->venue->name }}</a></li>
                        @endforeach
                    </ul>
                </section>
            @endif
            <section class="panel app-community-panel" id="team-join">
                <h2>Вступление в команду</h2>
                @if($isActiveTeamMember)
                    <p>Ты уже состоишь в этой команде.</p>
                @elseif($currentJoinRequest?->status === $joinStatus::PENDING)
                    <p>Твоя заявка ожидает решения команды.</p>
                    <a href="{{ route('account.notifications.threads.team', $currentJoinRequest->id) }}">Переписка по заявке</a>
                @elseif($currentJoinRequest?->status === $joinStatus::AWAITING_RESPONSE)
                    <p>Команда ожидает твоего ответа.</p>
                    <a href="{{ route('account.notifications.threads.team', $currentJoinRequest->id) }}">Переписка по заявке</a>
                @elseif($currentJoinRequest?->status === $joinStatus::ACCEPTED)
                    <p>Твоя заявка принята.</p>
                @elseif($currentJoinRequest?->status === $joinStatus::BLOCKED)
                    <p>Заявки в эту команду для тебя заблокированы.</p>
                    @if($currentJoinRequest->review_reason)<p>{{ $currentJoinRequest->review_reason }}</p>@endif
                @elseif($canSubmit)
                    @if($currentJoinRequest?->status === $joinStatus::REJECTED)
                        <p>Предыдущая заявка была отклонена. Можно отправить новую.</p>
                        @if($currentJoinRequest->review_reason)<p>{{ $currentJoinRequest->review_reason }}</p>@endif
                    @endif
                    <form method="POST" action="{{ route('teams.join-requests.store', $team->routeIdentifier()) }}">
                        @csrf
                        <button class="button primary" type="submit">Подать заявку на вступление</button>
                    </form>
                @elseif(auth()->guest() && $team->accepts_join_requests)
                    <a class="button secondary" href="{{ route('login') }}" data-auth-trigger>Войти, чтобы подать заявку</a>
                @else
                    <p>Команда сейчас не принимает общие заявки.</p>
                @endif
            </section>
        </div>
    </div>
</section>
@if($publicMembers->contains(fn ($person) => !empty($person['recipient_id'])))
    @include('theme::pages.account.partials.direct-message-dialog')
@endif
@if($contactPeople->count() > 1)
    @include('theme::pages.teams.partials.public-contact-dialog')
@endif
@endsection
