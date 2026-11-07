@php
    $isVenueEditor = ($page ?? null) === 'venues.edit' && isset($venue) && $venue !== null;
    $fallbackTitle = $isVenueEditor ? 'Редактирование: '.$venue->name : 'Раздел в разработке';
    $fallbackBreadcrumbs = $isVenueEditor
        ? [
            ['label' => 'Аккаунт', 'url' => route('account')],
            ['label' => 'Мои контракты', 'url' => route('account.contracts')],
            ['label' => 'Мои площадки', 'url' => route('account.contracts.venues')],
            ['label' => $venue->name],
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
