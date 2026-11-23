@php
    $title = 'Тарифная сетка';
    $editor = app(\App\Modules\Venue\Application\Services\AccountVenueEditorAccess::class)
        ->capabilities(auth()->user(), $venue);
    $breadcrumbs = [
        ['label' => 'Аккаунт', 'url' => route('account')],
        ['label' => 'Мои площадки', 'url' => route('account.contracts.venues')],
        ['label' => $venue->name, 'url' => route('account.venues.edit', ['alias' => $venue->routeIdentifier(), 'tab' => 'rental'])],
        ['label' => $title],
    ];
@endphp
@extends('theme::layouts.account', ['title' => $title.' · '.$venue->name, 'breadcrumbs' => $breadcrumbs])

@section('account-heading')
    @include('theme::pages.account.partials.section-heading', [
        'heading' => $venue->name,
        'subtitle' => $title,
    ])
@endsection

@section('account-content')
    <div class="app-venue-editor" data-venue-editor>
        @include('theme::pages.venues.partials.editor-tabs', ['activeTab' => 'rental', 'editor' => $editor])
        @include('venues.partials.tariff-grid')
    </div>
@endsection
