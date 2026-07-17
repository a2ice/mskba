@php
    $title = $venue?->name ?? 'Редактирование площадки';
    $breadcrumbs = $venue === null ? null : [
        ['label' => 'Площадки', 'url' => route('venues')],
        ['label' => $venue->name, 'url' => route('venues.show', $venue->alias)],
        ['label' => 'Редактирование'],
    ];
    $venueSidebarActive = 'edit';
@endphp

@extends('theme::layouts.section-sidebar', [
    'title' => $title,
    'sectionId' => 'venues',
    'sectionClass' => 'venues-section',
    'contentTitle' => $title,
    'sidebarLabel' => 'Навигация площадок',
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

    @if(session('error'))
        <div class="alert alert-danger">
            {{ session('error') }}
        </div>
    @endif

    @if(session('status'))
        <div class="alert alert-success">
            {{ session('status') }}
        </div>
    @endif

    @if($venue !== null)
        @include('theme::partials.venues.form', [
            'venue' => $venue,
            'types' => $types,
            'metros' => $metros,
            'action' => route('venues.update', $venue->alias),
            'method' => 'PUT',
            'cancelUrl' => route('venues.show', $venue->alias),
            'submitLabel' => 'Сохранить',
        ])

    @endif
@endsection
