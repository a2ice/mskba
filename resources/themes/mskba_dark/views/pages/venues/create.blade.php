@php
    $title = 'Добавить площадку';
@endphp


@extends('theme::layouts.section-sidebar', [
    'title' => $title,
    'sectionId' => 'venues',
    'sectionClass' => 'venues-section',
    'contentTitle' => $title,
    'contentSubtitle' => 'Выберите, как добавляете площадку, затем укажите основные данные.',
    'sidebarLabel' => 'Навигация площадок',
])

@section('section-sidebar')
    <div class="section-sidebar-block">
        @include('theme::partials.menu.sidebar', ['page' => 'venues'])
    </div>
@endsection

@section('section-content')
    @if(session('error'))
        <div class="alert alert-danger mb-3">
            {{ session('error') }}
        </div>
    @endif

    @include('theme::partials.venues.form', [
        'types' => $types,
        'action' => route('venues.store'),
        'cancelUrl' => route('venues'),
        'compactCreate' => true,
        'createWizard' => true,
        'submitLabel' => 'Добавить площадку',
    ])
@endsection
