@extends('theme::layouts.section-sidebar', [
    'title' => $team->name, 'sectionId' => 'teams', 'sectionClass' => 'teams-section',
    'contentTitle' => $team->name, 'contentSubtitle' => $team->description,
])
@section('section-sidebar')
<div class="section-sidebar-block"><h2 class="section-sidebar-block__title">Команда</h2><ul class="sidebar-nav nav flex-column">
<li class="nav-item active"><a class="nav-link active" href="{{ route('teams.show', $team->routeIdentifier()) }}">Обзор</a></li>
@if($canManage)<li class="nav-item"><a class="nav-link" href="{{ route('teams.edit', $team->routeIdentifier()) }}">Управление</a></li>@endif
</ul></div>
@endsection
@section('section-content')
<div class="section-card">
@if($team->logo)<img class="team-logo team-logo--large mb-3" src="{{ $team->logo->publicUrl() }}" alt="Логотип команды {{ $team->name }}">@endif
<div class="eyebrow">{{ $team->status->label() }}</div>
<p><strong>Дисциплины:</strong> {{ $team->sportProfiles->pluck('sport_type')->map->label()->join(', ') }}</p>
<h2>Состав</h2>
<div class="d-flex flex-column gap-2 mt-3">@forelse($team->memberships->where('contract.status.value', 'active') as $membership)
@php($memberName = trim(implode(' ', array_filter([$membership->user->profile?->first_name, $membership->user->profile?->last_name]))) ?: $membership->user->username)
<div class="d-flex justify-content-between"><span>{{ $memberName }}</span>
<span>{{ \App\Modules\Contract\Domain\Enums\TeamMembershipAccessLevelEnum::from($membership->access_level)->label() }}</span></div>
@empty <p>Активный состав пока пуст.</p>@endforelse</div></div>
@endsection
