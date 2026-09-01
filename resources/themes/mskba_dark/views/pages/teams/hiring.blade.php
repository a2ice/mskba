@extends('theme::layouts.section-sidebar', [
    'title' => 'Набор в команду', 'sectionId' => 'teams', 'sectionClass' => 'teams-section',
    'contentTitle' => 'Набор в команду', 'contentSubtitle' => $team->name,
])
@section('section-sidebar')
<div class="section-sidebar-block"><h2 class="section-sidebar-block__title">Команда</h2><ul class="sidebar-nav nav flex-column">
<li class="nav-item"><a class="nav-link" href="{{ route('teams.show', $team->routeIdentifier()) }}">Обзор</a></li>
@if($canEditSettings)<li class="nav-item"><a class="nav-link" href="{{ route('teams.edit', $team->routeIdentifier()) }}">Основные настройки</a></li>@endif
@if($canManageMembersAndRoster)<li class="nav-item"><a class="nav-link" href="{{ route('teams.management', $team->routeIdentifier()) }}">Состав и участники</a></li>@endif
@if($canManageJoinRequests)<li class="nav-item"><a class="nav-link" href="{{ route('teams.join-requests.index', $team->routeIdentifier()) }}">Заявки на вступление</a></li>@endif
@if($canManageVenues)<li class="nav-item"><a class="nav-link" href="{{ route('teams.venues.index', $team->routeIdentifier()) }}">Площадки</a></li>@endif
<li class="nav-item active"><a class="nav-link active" href="{{ route('teams.hiring.index', $team->routeIdentifier()) }}">Набор</a></li>
</ul></div>
@endsection
@section('section-content')
@if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif

<section class="section-card mb-4">
    <h2>Открыть вакансию</h2>
    <p class="form-hint mb-3">Критерии помогают игроку понять ожидания команды, но не блокируют подачу заявки автоматически.</p>
    <form method="POST" action="{{ route('teams.hiring.store', $team->routeIdentifier()) }}">
        @csrf
        @include('theme::pages.teams.partials.hiring-fields', ['prefix' => 'new', 'vacancy' => null])
        <button class="btn btn--primary mt-3" type="submit">Открыть вакансию</button>
    </form>
</section>

<section>
    <h2>Вакансии</h2>
    <div class="section-list mt-3">
        @forelse($hiringPositions as $vacancy)
            <article class="section-card">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <div>
                        <strong>{{ $vacancy->status->label() }}</strong>
                        <p class="form-hint mb-0">Свободно {{ $vacancy->remainingSpots() }} из {{ $vacancy->spots_total }}</p>
                    </div>
                    <form method="POST" action="{{ route('teams.hiring.status', [$team->routeIdentifier(), $vacancy->id]) }}">
                        @csrf @method('PATCH')
                        <input type="hidden" name="action" value="{{ $vacancy->status === \App\Modules\Team\Domain\Enums\TeamHiringStatusEnum::ACTIVE ? 'close' : 'reopen' }}">
                        <button class="btn btn--secondary btn--sm" type="submit">{{ $vacancy->status === \App\Modules\Team\Domain\Enums\TeamHiringStatusEnum::ACTIVE ? 'Закрыть набор' : 'Открыть снова' }}</button>
                    </form>
                </div>
                <form method="POST" action="{{ route('teams.hiring.update', [$team->routeIdentifier(), $vacancy->id]) }}">
                    @csrf @method('PUT')
                    @include('theme::pages.teams.partials.hiring-fields', ['prefix' => 'vacancy-'.$vacancy->id, 'vacancy' => $vacancy])
                    <button class="btn btn--primary btn--sm mt-3" type="submit">Сохранить</button>
                </form>
            </article>
        @empty
            <p class="team-profile__empty">Вакансий пока нет.</p>
        @endforelse
    </div>
</section>
@endsection
