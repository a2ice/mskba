@php
    $title = 'Тарифная сетка · '.$venue->name;
    $venueSidebarActive = 'booking-policy';
@endphp
@extends('theme::layouts.section-sidebar', [
    'title' => $title,
    'sectionId' => 'account',
    'sectionClass' => 'account-section',
    'contentTitle' => $title,
    'sidebarLabel' => 'Управление площадкой',
])

@section('section-sidebar')
    @include('theme::partials.venues.internal-sidebar')
@endsection

@section('section-content')
    @include('venues.partials.tariff-grid')
@endsection
