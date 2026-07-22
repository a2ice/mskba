@php $title = 'Расписание площадки'; @endphp

@extends('theme::partials.admin.list-shell', ['title' => $title, 'subtitle' => $venue->name.' · #'.$venue->id])

@section('section-content')
    @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    <div class="admin-card">
        @include('theme::partials.venues.schedule-editor', [
            'action' => route('admin.venues.schedule.update', $venue),
            'cancelUrl' => route('admin.venues.edit', $venue),
            'cancelLabel' => 'К редактированию',
        ])
    </div>
@endsection
