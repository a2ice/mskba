@php
    $headCoach = $section->headCoachMembership?->user;
    $headCoachName = trim(($headCoach?->profile?->first_name ?? '').' '.($headCoach?->profile?->last_name ?? '')) ?: ($headCoach?->username ?? 'Не указан');
    $canContact = $contacts->isNotEmpty() || filled($section->contact_notes);
    $publicCoaches = $section->coachMemberships
        ->filter(fn ($membership) => $membership->user !== null && in_array('coach', $membership->sportRoleValues(), true))
        ->sortByDesc(fn ($membership) => $membership->id === $section->head_coach_membership_id)
        ->unique('user_id')
        ->values();
    $nextSession = $section->trainingSessions->first();
    $targetYear = $section->getAttribute('target_year');
    $targetYearFrom = $section->getAttribute('target_year_from');
    $targetYearTo = $section->getAttribute('target_year_to');
    $targetYearLabel = $targetYear !== null
        ? $targetYear.' г.р.'
        : (($targetYearFrom !== null && $targetYearTo !== null) ? $targetYearFrom.'–'.$targetYearTo.' г.р.' : 'Без ограничения');
    $venueAddress = $section->primaryVenue?->raw_address
        ?: $section->primaryVenue?->location?->address?->full_address;
    $pricingLabel = $section->pricing_type->value === 'free'
        ? 'Бесплатно'
        : ($section->single_session_price_minor !== null
            ? number_format($section->single_session_price_minor / 100, 0, ',', ' ').' ₽ / занятие'
            : 'Платно');
    $recruitmentLabel = $section->is_recruiting
        ? 'Идёт набор'
        : ($section->accepts_trainee_requests ? 'Принимает заявки' : 'Набор закрыт');
    $breadcrumbs = [
        ['label' => 'Секции', 'url' => route('sports-sections.index')],
        ['label' => $section->name],
    ];
@endphp

@extends('theme::layouts.section-sidebar', [
    'title' => $section->name,
    'sectionId' => 'sports-section',
    'sectionClass' => 'sports-section-public',
    'contentTitle' => $section->name,
    'contentSubtitle' => null,
    'sidebarLabel' => 'Навигация секции',
])

@section('section-sidebar')
    <div class="section-sidebar-block">
        <h2 class="section-sidebar-block__title">Секция</h2>
        <nav class="sports-section-side-nav" aria-label="Разделы секции">
            <a href="#section-overview">Обзор</a>
            <a href="#section-sessions">Занятия</a>
            @if($publicCoaches->isNotEmpty())<a href="#section-coaches">Тренеры</a>@endif
            @if($section->primaryVenue)<a href="#section-venue">Площадка</a>@endif
            <a href="#section-pricing">Стоимость</a>
            @if($section->teams->isNotEmpty())<a href="#section-teams">Команды</a>@endif
            @if($section->media->isNotEmpty())<a href="#section-photos">Фото</a>@endif
            @if($canContact)<a href="#section-contacts">Контакты</a>@endif
        </nav>
    </div>

    <div class="section-sidebar-block">
        <h2 class="section-sidebar-block__title">Информация</h2>
        <dl class="sports-section-side-meta">
            <div><dt>Направление</dt><dd>{{ $section->game_format->label() }}</dd></div>
            <div><dt>Формат</dt><dd>{{ $section->training_mode->label() }}</dd></div>
            <div><dt>Год рождения</dt><dd>{{ $targetYearLabel }}</dd></div>
            <div><dt>Занимаются</dt><dd>{{ (int) $section->active_trainees_count }}</dd></div>
            <div><dt>Запись</dt><dd>{{ $recruitmentLabel }}</dd></div>
        </dl>
    </div>

    <div class="section-sidebar-block">
        <h2 class="section-sidebar-block__title">Управление</h2>
        @if($canManageSection || $canManageTrainees || $canManageSessions)
            <div class="sports-section-management-actions">
                @if($canManageSection)
                    <a class="btn btn--secondary btn--sm" href="{{ route('account.sports-sections.edit', $section) }}">Редактировать секцию</a>
                    <a class="btn btn--secondary btn--sm" href="{{ route('account.sports-sections.teams', $section) }}">Связанные команды</a>
                @endif
                @if($canManageTrainees)
                    <a class="btn btn--secondary btn--sm" href="{{ route('account.sports-sections.applications', $section) }}">Заявки и набор</a>
                @endif
                @if($canManageSessions)
                    <a class="btn btn--secondary btn--sm" href="{{ route('account.sports-sections.edit', $section) }}#section-sessions">Занятия</a>
                @endif
            </div>
        @else
            <p class="section-sidebar-block__text">Действия управления появятся здесь при наличии прав на секцию.</p>
        @endif
    </div>
@endsection

@section('section-mobile-sticky-navigation')
    <nav class="sports-section-mobile-nav" aria-label="Разделы секции">
        <a href="#section-overview">Обзор</a>
        <a href="#section-sessions">Занятия</a>
        @if($section->primaryVenue)<a href="#section-venue">Площадка</a>@endif
        <a href="#section-pricing">Стоимость</a>
        @if($canContact)<a href="#section-contacts">Контакты</a>@endif
    </nav>
@endsection

@section('section-heading-action')
    <div class="sports-section-heading-actions">
        @if($isActiveTrainee)
            <span class="btn btn--secondary btn--sm" aria-disabled="true">Вы уже занимаетесь</span>
        @elseif($currentJoinRequest)
            <span class="btn btn--secondary btn--sm" aria-disabled="true">Заявка на рассмотрении</span>
        @elseif($section->accepts_trainee_requests)
            @auth
                @if($canApply)
                    <form method="POST" action="{{ route('sports-sections.applications.store', $section) }}">@csrf
                        <button class="btn btn--primary btn--sm" type="submit">Записаться</button>
                    </form>
                @else
                    <span class="btn btn--secondary btn--sm" aria-disabled="true">Запись недоступна</span>
                @endif
            @else
                <button
                    class="btn btn--primary btn--sm"
                    type="button"
                    data-handler="modal"
                    data-modal-action="open"
                    data-modal-target="auth-entry-classic"
                    data-auth-redirect-url="{{ route('sports-sections.show', $section, false) }}"
                >Записаться</button>
            @endauth
        @else
            <span class="sports-section-badge">Набор закрыт</span>
        @endif

        @if($canManageSection)
            <a class="btn btn--secondary btn--sm" href="{{ route('account.sports-sections.edit', $section) }}">Управлять</a>
        @endif
    </div>
@endsection

@section('section-content')
<div class="sports-section-show">
    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    @if($errors->has('section'))<div class="alert alert-danger">{{ $errors->first('section') }}</div>@endif

    <section class="sports-section-public__hero" id="section-overview" aria-label="Краткая информация о секции">
        <div class="sports-section-public__media">
            <img src="{{ $section->featuredMedia?->publicUrl() ?? asset('images/venue-placeholder.png') }}" alt="{{ $section->name }}">
            <div class="sports-section-public__media-badges">
                <span class="sports-section-badge">{{ $section->game_format->label() }}</span>
                <span class="sports-section-badge">{{ $section->training_mode->label() }}</span>
                @if($section->is_recruiting)
                    <span class="sports-section-badge sports-section-badge--recruiting">Идёт набор</span>
                @elseif($section->accepts_trainee_requests)
                    <span class="sports-section-badge">Принимает заявки</span>
                @endif
            </div>
        </div>

        <div class="sports-section-public__summary">
            <p class="sports-section-public__eyebrow">Спортивная секция</p>
            <p class="sports-section-public__description">{{ $section->description ?: 'Описание секции пока не добавлено.' }}</p>

            <div class="sports-section-public__quick-facts">
                <div><span>Ближайшее занятие</span><strong>{{ $nextSession ? $nextSession->starts_at->timezone(config('app.timezone'))->format('d.m, H:i') : 'Пока не назначено' }}</strong></div>
                <div><span>Площадка</span><strong>{{ $section->primaryVenue?->name ?? 'Уточняется' }}</strong></div>
                <div><span>Стоимость</span><strong>{{ $pricingLabel }}</strong></div>
                <div><span>Год рождения</span><strong>{{ $targetYearLabel }}</strong></div>
            </div>

            <div class="sports-section-show__hero-actions">
                @if($currentJoinRequest)
                    <form method="POST" action="{{ route('sports-sections.applications.cancel', [$section, $currentJoinRequest]) }}">@csrf @method('PATCH')
                        <button class="btn btn--secondary" type="submit">Отменить заявку</button>
                    </form>
                @elseif($section->accepts_trainee_requests && auth()->check() && ! $canApply && ! $isActiveTrainee)
                    <span class="sports-section-show__application-hint">Для записи нужен подтверждённый активный профиль игрока.</span>
                @elseif(! $section->accepts_trainee_requests)
                    <span class="sports-section-show__application-hint">Секция сейчас не принимает новые заявки.</span>
                @endif
                @if($canContact)<a class="btn btn--secondary" href="#section-contacts">Связаться</a>@endif
            </div>
        </div>
    </section>

    <section class="sports-section-public__section" id="section-sessions">
        <div class="sports-section-public__section-heading">
            <div><span>Расписание</span><h2>Ближайшие подтверждённые занятия</h2></div>
            @if($canManageSessions)<a class="btn btn--secondary btn--sm" href="{{ route('account.sports-sections.edit', $section) }}#section-sessions">Управлять занятиями</a>@endif
        </div>
        <div class="sports-section-public__cards">
            @forelse($section->trainingSessions as $session)
                <article class="sports-section-public__card">
                    <strong>{{ $session->starts_at->timezone(config('app.timezone'))->format('d.m.Y, H:i') }}–{{ $session->ends_at->timezone(config('app.timezone'))->format('H:i') }}</strong>
                    <span>{{ $session->venue?->name ?? 'Место уточняется' }}@if($session->venueCourt) · {{ $session->venueCourt->name }}@endif</span>
                    @if($session->event)<a class="btn btn--secondary btn--sm" href="{{ route('events.show', $session->event) }}">Открыть мероприятие</a>@elseif($canContact)<a href="#section-contacts">Уточнить участие</a>@endif
                </article>
            @empty
                <p class="text-muted">Подтверждённых будущих занятий пока нет.</p>
            @endforelse
        </div>
    </section>

    @if($publicCoaches->isNotEmpty())
        <section class="sports-section-public__section" id="section-coaches">
            <div class="sports-section-public__section-heading"><div><span>Команда секции</span><h2>Тренеры</h2></div></div>
            <div class="sports-section-public__cards sports-section-public__cards--people">
                @foreach($publicCoaches as $membership)
                    @php($coach = $membership->user)
                    @php($coachName = trim(($coach?->profile?->first_name ?? '').' '.($coach?->profile?->last_name ?? '')) ?: ($coach?->username ?? 'Тренер'))
                    <article class="sports-section-public__person-card">
                        <div class="sports-section-public__person-avatar"><i class="ti ti-user" aria-hidden="true"></i></div>
                        <div><strong>{{ $coachName }}</strong><span>{{ $membership->id === $section->head_coach_membership_id ? 'Главный тренер' : 'Тренер' }}</span></div>
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    @if($section->primaryVenue)
        <section class="sports-section-public__section" id="section-venue">
            <div class="sports-section-public__section-heading"><div><span>Где проходят тренировки</span><h2>Площадка</h2></div></div>
            <article class="sports-section-public__venue-card">
                <div>
                    <strong>{{ $section->primaryVenue->name }}</strong>
                    @if($section->primaryVenueCourt)<span>{{ $section->primaryVenueCourt->name }}</span>@endif
                    @if($venueAddress)<span>{{ $venueAddress }}</span>@endif
                </div>
                <a class="btn btn--secondary btn--sm" href="{{ route('venues.show', $section->primaryVenue->routeIdentifier()) }}">Открыть площадку</a>
            </article>
        </section>
    @endif

    <section class="sports-section-public__section" id="section-pricing">
        <div class="sports-section-public__section-heading"><div><span>Условия участия</span><h2>Стоимость и тарифы</h2></div></div>
        <div class="sports-section-public__pricing-lead"><strong>{{ $pricingLabel }}</strong></div>
        @if($section->pricingPlans->isNotEmpty())
            <div class="sports-section-public__cards">
                @foreach($section->pricingPlans as $plan)
                    <article class="sports-section-public__card">
                        <strong>{{ $plan->name }}</strong>
                        <span>{{ number_format($plan->amount_minor / 100, 0, ',', ' ') }} ₽</span>
                        <small>{{ $plan->sessions_count ? $plan->sessions_count.' занятий' : 'Без лимита занятий' }}{{ $plan->duration_days ? ' · '.$plan->duration_days.' дней' : '' }}</small>
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    @if($section->teams->isNotEmpty())
        <section class="sports-section-public__section" id="section-teams">
            <div class="sports-section-public__section-heading"><div><span>Продолжение спортивного пути</span><h2>Связанные команды</h2></div></div>
            <div class="sports-section-public__team-list">
                @foreach($section->teams as $team)
                    <a class="btn btn--secondary" href="{{ route('teams.show', $team->routeIdentifier()) }}"><i class="ti ti-users-group" aria-hidden="true"></i>{{ $team->name }}</a>
                @endforeach
            </div>
        </section>
    @endif

    @if($section->media->isNotEmpty())
        <section class="sports-section-public__section" id="section-photos">
            <div class="sports-section-public__section-heading"><div><span>Секция в кадре</span><h2>Фотографии</h2></div></div>
            <div class="sports-section-show__gallery">
                @foreach($section->media as $photo)<img src="{{ $photo->publicUrl() }}" alt="{{ $section->name }}" loading="lazy">@endforeach
            </div>
        </section>
    @endif

    @if($canContact)
        <section class="sports-section-public__section" id="section-contacts">
            <div class="sports-section-public__section-heading"><div><span>Связь с секцией</span><h2>Контакты</h2></div></div>
            <div class="sports-section-public__contacts">
                @foreach($contacts as $contact)<p><strong>{{ $contact->type->label() }}:</strong> {{ $contact->displayValue() }}</p>@endforeach
                @if($section->contact_notes)<p class="text-muted">{{ $section->contact_notes }}</p>@endif
            </div>
        </section>
    @endif
</div>
@endsection
