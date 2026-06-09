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

@section('section-content')
    @if(isset($error))
        <div class="alert alert-danger">
            {{ $error['message'] }}
        </div>
    @endif

    @if($venues !== null)
        @if ($venues === [])
            <div class="alert alert-info">
                Площадки пока не назначены.
            </div>
        @else
            @foreach ($venues as $venue)
                <div class="venue-item mb-5">
                    <h5>
                        <a href="{{ route('account.venues.show', $venue->alias) }}">
                            {{ $venue->name }}
                        </a>
                    </h5>
                    <p>Статус: {{ $venue->status }}</p>
                    <p>Описание: {{ $venue->description }}</p>
                    @if($venue->rawAddress)
                        <p>Адрес: {{ $venue->rawAddress }}</p>
                    @endif
                    @if ($venue->canEdit)
                        <a href="{{ route('account.venues.edit', $venue->alias) }}" class="btn btn-primary">Редактировать</a>
                    @endif
                <hr>
                </div>
            @endforeach 
        @endif

        @can('add_venue', $user)
            <a href="{{ route('account.venues.create') }}" class="btn btn-success">Добавить площадку</a>
        @endcan
    @endif
@endsection
