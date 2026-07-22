@php 
    $venues = isset($venues) ? $venues : null;
    $user = isset($user) ? $user : auth()->user();
@endphp

@php $title = 'Мои площадки'; @endphp

@extends('theme::layouts.section-sidebar', [
    'title' => $title,
    'sectionId' => 'account',
    'sectionClass' => 'account-section',
    'contentTitle' => $title,
    'sidebarLabel' => 'Навигация аккаунта',
    'wrapSidebarPanel' => false,
    'sidebarPartial' => 'theme::partials.account.sidebar',
])

@section('section-heading-action')
    <a href="{{ route('venues.create') }}" class="btn btn--primary btn--sm">Добавить площадку</a>
@endsection

@section('section-content')
    @if(isset($error))
        <div class="alert alert-danger">
            {{ $error['message'] }}
        </div>
    @endif

    @if($venues !== null)
        @if ($venues === [])
            <div class="alert alert-info">
                У вас пока нет площадок.
            </div>
        @else
            <div class="section-list account-venue-list">
                @foreach ($venues as $venue)
                    <article class="section-list-item account-venue-list__item">
                        <div class="account-venue-list__heading">
                            <div>
                                <p class="account-venue-list__type">{{ $venue->type }}</p>
                                <h2 class="h5 mb-0">
                                    <a href="{{ route('account.venues.show', $venue->routeIdentifier()) }}">
                                        {{ $venue->name }}
                                    </a>
                                </h2>
                            </div>
                            @include('theme::partials.venues.account-status', ['venue' => $venue])
                        </div>

                        @if($venue->shortDescription)
                            <p class="mb-2">{{ $venue->shortDescription }}</p>
                        @endif
                        @if($venue->rawAddress)
                            <p class="account-venue-list__address mb-3">{{ $venue->rawAddress }}</p>
                        @endif

                        <div class="account-venue-list__actions">
                            <a href="{{ route('account.venues.show', $venue->routeIdentifier()) }}" class="btn btn--secondary btn--sm">Открыть</a>
                            @if ($venue->canEdit)
                                <a href="{{ route('account.venues.edit', $venue->routeIdentifier()) }}" class="btn btn--secondary btn--sm">Редактировать</a>
                            @endif
                            <a href="{{ route('account.venues.status', $venue->routeIdentifier()) }}" class="btn btn--secondary btn--sm">Модерация</a>
                            @if ($venue->canEditSchedule)
                                <a href="{{ route('account.venues.schedule.edit', $venue->routeIdentifier()) }}" class="btn btn--secondary btn--sm">Расписание</a>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
        @endif

    @endif
@endsection
