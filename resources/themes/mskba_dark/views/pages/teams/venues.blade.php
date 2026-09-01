@extends('theme::layouts.section-sidebar', [
    'title' => 'Площадки команды', 'sectionId' => 'teams', 'sectionClass' => 'teams-section',
    'contentTitle' => 'Площадки команды', 'contentSubtitle' => $team->name,
])
@section('section-sidebar')
<div class="section-sidebar-block"><h2 class="section-sidebar-block__title">Команда</h2><ul class="sidebar-nav nav flex-column">
<li class="nav-item"><a class="nav-link" href="{{ route('teams.show', $team->routeIdentifier()) }}">Обзор</a></li>
@if($canEditSettings)<li class="nav-item"><a class="nav-link" href="{{ route('teams.edit', $team->routeIdentifier()) }}">Основные настройки</a></li>@endif
@if($canManageMembersAndRoster)<li class="nav-item"><a class="nav-link" href="{{ route('teams.management', $team->routeIdentifier()) }}">Состав и участники</a></li>@endif
@if($canManageJoinRequests)<li class="nav-item"><a class="nav-link" href="{{ route('teams.join-requests.index', $team->routeIdentifier()) }}">Заявки на вступление</a></li>@endif
<li class="nav-item active"><a class="nav-link active" href="{{ route('teams.venues.index', $team->routeIdentifier()) }}">Площадки</a></li>
@if($canManageHiring)<li class="nav-item"><a class="nav-link" href="{{ route('teams.hiring.index', $team->routeIdentifier()) }}">Набор</a></li>@endif
</ul></div>
@endsection
@section('section-content')
@if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif

<section class="section-card mb-4">
    <h2>Добавить желаемую площадку</h2>
    <p class="form-hint mb-3">Выберите подтверждённую площадку, на которой команда хотела бы играть. Это не означает официальной привязки.</p>
    <form method="POST" action="{{ route('teams.venues.store', $team->routeIdentifier()) }}">
        @csrf
        @include('theme::partials.venues.predictive-selector', [
            'id' => 'teamDesiredVenue',
            'name' => 'venue_id',
            'label' => 'Площадка',
            'confirmedOnly' => true,
            'showBookingScope' => false,
        ])
        <button class="btn btn--primary mt-3" type="submit">Добавить площадку</button>
    </form>
</section>

<section class="section-card">
    <h2>Желаемые площадки</h2>
    <div class="section-list mt-3">
        @forelse($venueRelations as $relation)
            <article class="section-card">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                    <div>
                        <strong><a href="{{ route('venues.show', $relation->venue->routeIdentifier()) }}">{{ $relation->venue->name }}</a></strong>
                        <p class="form-hint mb-0">{{ $relation->venue->raw_address ?: 'Адрес не указан' }} · {{ $relation->relation_type->label() }}</p>
                    </div>
                    @if($relation->relation_type === \App\Modules\Team\Domain\Enums\TeamVenueRelationTypeEnum::DESIRED)
                        <form method="POST" action="{{ route('teams.venues.destroy', [$team->routeIdentifier(), $relation->id]) }}" onsubmit="return confirm('Удалить площадку из желаемых?')">
                            @csrf @method('DELETE')
                            <button class="btn btn--secondary btn--sm" type="submit">Удалить</button>
                        </form>
                    @endif
                </div>
            </article>
        @empty
            <p class="team-profile__empty">Желаемые площадки пока не добавлены.</p>
        @endforelse
    </div>
</section>
@endsection
