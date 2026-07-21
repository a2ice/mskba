@php $title = 'Редактирование площадки'; @endphp

@extends('theme::partials.admin.list-shell', [
    'title' => $title,
    'subtitle' => $venue->name.' · #'.$venue->id,
])

@section('section-content')
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <div class="admin-card">
        @include('theme::partials.venues.gallery-editor', [
            'venue' => $venue,
            'photos' => $venuePhotos ?? [],
            'adminMode' => true,
        ])

        @include('theme::partials.venues.form', [
            'venue' => $venue,
            'types' => $types,
            'metros' => $metros,
            'action' => route('admin.venues.update', $venue),
            'method' => 'PUT',
            'cancelUrl' => route('admin.venues'),
            'submitLabel' => 'Сохранить',
        ])
    </div>
@endsection
