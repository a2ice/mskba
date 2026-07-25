@php
    $title = $selectedType?->newLabel() ?? 'Новое мероприятие';
    $createLabel = $selectedType?->createLabel() ?? 'Создать мероприятие';
@endphp

@extends('theme::layouts.section-sidebar', [
    'title' => $title,
    'sectionId' => 'events',
    'sectionClass' => 'events-section',
    'contentTitle' => $title,
    'contentSubtitle' => 'Выберите площадку и свободное время.',
    'sidebarLabel' => 'Навигация мероприятий',
])

@section('section-sidebar')
    <div class="section-sidebar-block">
        <h2 class="section-sidebar-block__title">Мероприятия</h2>
        <ul class="sidebar-nav nav flex-column">
            <li class="nav-item"><a class="nav-link" href="{{ route('events.index') }}">Все мероприятия</a></li>
            <li class="nav-item active"><a class="nav-link active" href="{{ route('events.create', array_filter(['type' => $selectedType?->value])) }}">{{ $createLabel }}</a></li>
        </ul>
    </div>
@endsection

@section('section-content')
    @if(session('error')) <div class="alert alert-danger mb-3">{{ session('error') }}</div> @endif
    @if($venues->isEmpty())
        <div class="alert alert-info">Нет доступных подтверждённых площадок.</div>
    @else
        @include('theme::pages.events.partials.create-form', [
            'formAction' => route('events.store'),
            'submitLabel' => $createLabel,
        ])
    @endif
@endsection
