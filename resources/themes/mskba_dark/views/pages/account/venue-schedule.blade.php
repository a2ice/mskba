@php
    $venue = isset($venue) ? $venue : null;
    $title = $venue ? 'Расписание: ' . $venue->name : 'Расписание площадки';
    $venueSidebarActive = 'schedule';
@endphp

@extends('theme::layouts.section-sidebar', [
    'title' => $title,
    'sectionId' => 'venues',
    'sectionClass' => 'venues-section',
    'contentTitle' => $title,
    'sidebarLabel' => 'Навигация площадки',
])

@section('section-sidebar')
    @if($venue !== null)
        @include('theme::partials.venues.internal-sidebar')
    @endif
@endsection

@section('section-content')
    @if(isset($error))
        <div class="alert alert-danger">
            {{ $error['message'] }}
        </div>
    @endif

    @if(session('status'))
        <div class="alert alert-success">
            {{ session('status') }}
        </div>
    @endif

    @if(session('error'))
        <div class="alert alert-danger">
            {{ session('error') }}
        </div>
    @endif

    @if($venue !== null)
        @include('theme::partials.venues.schedule-editor', [
            'action' => route('account.venues.schedule.update', $venue->routeIdentifier()),
            'cancelUrl' => route('account.venues.show', $venue->routeIdentifier()),
        ])
    @endif
@endsection
