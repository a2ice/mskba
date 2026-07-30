@extends('theme::layouts.section-sidebar', [
    'title' => 'Команды', 'sectionId' => 'teams', 'sectionClass' => 'teams-section',
    'contentTitle' => 'Команды', 'contentSubtitle' => 'Постоянные баскетбольные команды сообщества.',
])
@section('section-sidebar')
<div class="section-sidebar-block"><h2 class="section-sidebar-block__title">Команды</h2><ul class="sidebar-nav nav flex-column">
<li class="nav-item active"><a class="nav-link active" href="{{ route('teams.index') }}">Все команды</a></li>
@auth <li class="nav-item"><a class="nav-link" href="{{ route('teams.create') }}">Создать команду</a></li> @endauth
</ul></div>
@endsection
@section('section-content')
<div class="d-flex flex-column gap-3">
@forelse($teams as $team)
<article class="section-card"><div class="d-flex justify-content-between gap-3 flex-wrap">
<div class="team-list-card">@if($team->logo)<img class="team-logo" src="{{ $team->logo->publicUrl() }}" alt="">@endif<div><div class="eyebrow">{{ $team->status->label() }}</div><h2>{{ $team->name }}</h2>
<p>{{ $team->description ?: 'Описание пока не добавлено.' }}</p><small>Участников: {{ $team->memberships_count }}</small></div>
</div>
<a class="btn btn--secondary" href="{{ route('teams.show', $team->routeIdentifier()) }}">Открыть</a>
</div></article>
@empty <div class="alert alert-info">Команд пока нет.</div> @endforelse
</div><div class="mt-3">{{ $teams->links() }}</div>
@endsection
