@php
    $venue = isset($venue) ? $venue : null;
    $title = $venue ? 'Расписание: ' . $venue->name : 'Расписание площадки';
    $venueSidebarActive = 'schedule';
    $breadcrumbs = $venue === null ? null : [
        ['label' => 'Аккаунт', 'url' => route('account')],
        ['label' => 'Мои площадки', 'url' => route('account.venues')],
        ['label' => $venue->name, 'url' => route('account.venues.show', $venue->routeIdentifier())],
        ['label' => 'Расписание'],
    ];
@endphp

@extends('theme::layouts.section-sidebar', [
    'title' => $title,
    'sectionId' => 'account',
    'sectionClass' => 'account-section',
    'contentTitle' => $title,
    'sidebarLabel' => 'Управление площадкой',
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
