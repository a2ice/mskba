@php
    $creator = $team->createdByActor?->user;
    $creatorName = trim(implode(' ', array_filter([$creator?->profile?->first_name, $creator?->profile?->last_name]))) ?: $creator?->username ?: 'Не указан';
    $memberName = static fn ($membership): string => trim(implode(' ', array_filter([$membership->user?->profile?->first_name, $membership->user?->profile?->last_name]))) ?: $membership->user?->username ?: 'Пользователь #'.$membership->user_id;
@endphp

@extends('theme::layouts.section-sidebar', [
    'title' => 'Команда · '.$team->name,
    'sectionId' => 'admin',
    'sectionClass' => 'admin-section',
    'contentTitle' => $team->name,
    'contentSubtitle' => 'Административный контекст',
])

@section('section-sidebar')
<div class="section-sidebar-block"><h2 class="section-sidebar-block__title">Администрирование</h2><ul class="sidebar-nav nav flex-column">
<li class="nav-item"><a class="nav-link" href="{{ route('admin.teams') }}">Все команды</a></li>
<li class="nav-item active"><a class="nav-link active" href="{{ route('admin.teams.show', $team->routeIdentifier()) }}">Карточка команды</a></li>
</ul></div>
@endsection

@section('section-content')
<section class="section-card mb-4">
    <div class="d-flex flex-wrap justify-content-between gap-3 align-items-start">
        <div><span class="eyebrow">Системные сведения</span><h2>{{ $team->name }}</h2><p>{{ $team->description ?: 'Описание не заполнено.' }}</p></div>
        @unless($team->trashed())<a class="btn btn--secondary btn--sm" href="{{ route('teams.show', $team->routeIdentifier()) }}" target="_blank" rel="noopener">Посмотреть на сайте</a>@endunless
    </div>
    <dl class="venue-side-meta mt-3">
        <div><dt>ID</dt><dd>{{ $team->id }}</dd></div>
        <div><dt>Статус</dt><dd>{{ $team->status->label() }}{{ $team->trashed() ? ' · удалена' : '' }}</dd></div>
        <div><dt>Тип</dt><dd>{{ $team->isTemporary() ? 'Временная команда мероприятия' : 'Постоянная команда' }}</dd></div>
        <div><dt>Создатель</dt><dd>{{ $creatorName }}</dd></div>
        <div><dt>Создана</dt><dd>{{ $team->created_at?->format('d.m.Y H:i') }}</dd></div>
        <div><dt>Обновлена</dt><dd>{{ $team->updated_at?->format('d.m.Y H:i') }}</dd></div>
    </dl>
</section>

<section class="section-card mb-4">
    <h2>Дисциплины</h2>
    <p>{{ $team->sportProfiles->map(fn($profile) => $profile->sport_type->label())->join(', ') ?: 'Не указаны' }}</p>
    @if($team->temporaryForEvent)<p class="form-hint">Связано с мероприятием: <a href="{{ route('admin.events.show', $team->temporaryForEvent->routeIdentifier()) }}">{{ $team->temporaryForEvent->title }}</a></p>@endif
</section>

<section class="section-card">
    <h2>Активные членства</h2>
    @forelse($activeMemberships as $membership)
        <article class="section-card mb-3">
            <strong>{{ $memberName($membership) }}</strong>
            <p class="form-hint">Уровень доступа: {{ $membership->access_level }} · спортивные роли: {{ $membership->sportRoles()->map(fn($role) => $role->label())->join(', ') ?: 'нет' }}{{ $membership->is_captain ? ' · капитан' : '' }}</p>
            <p class="form-hint">Договор: {{ $membership->contract?->name }} · права: {{ $membership->contract?->permissions->pluck('permission')->join(', ') ?: 'нет' }}</p>
        </article>
    @empty<p class="form-hint">Активных членств нет.</p>@endforelse
</section>
@endsection
