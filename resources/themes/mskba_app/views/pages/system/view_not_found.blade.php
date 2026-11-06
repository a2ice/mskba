@php
    $isVenueEditor = ($page ?? null) === 'venues.edit' && isset($venue) && $venue !== null;
    $fallbackTitle = $isVenueEditor ? 'Редактирование: '.$venue->name : 'Раздел в разработке';
    $fallbackBreadcrumbs = $isVenueEditor
        ? [
            ['label' => 'Площадки', 'url' => route('venues')],
            ['label' => $venue->name, 'url' => route('venues.show', $venue->routeIdentifier())],
            ['label' => 'Редактирование'],
        ]
        : null;
@endphp
@extends('theme::layouts.app', ['title' => $fallbackTitle, 'breadcrumbs' => $fallbackBreadcrumbs])

@section('content')
    <section class="section" aria-label="Раздел в разработке">
        <div class="container">
            <h1>MSKBA App theme</h1>
            <p>Новая тема находится в разработке.</p>
        </div>
    </section>
@endsection
