@php $venue = isset($venue) ? $venue : null; @endphp

@php $title = $venue ? $venue->name : 'Площадка'; @endphp

@extends('theme::layouts.section-sidebar', [
    'title' => $title,
    'sectionId' => 'account',
    'sectionClass' => 'account-section',
    'contentTitle' => $title,
    'sidebarLabel' => 'Навигация аккаунта',
    'wrapSidebarPanel' => false,
    'sidebarPartial' => 'theme::partials.account.sidebar',
])

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

    @if($venue !== null)
        <ul class="list-unstyled mb-4">
            <li class="mb-3">
                Тип:
                <span class="fw-bold">{{ $venue->type }}</span>
            </li>
            <li class="mb-3">
                Статус:
                <span class="fw-bold">{{ $venue->status }}</span>
            </li>
            <li class="mb-3">
                Описание:
                <span class="fw-bold">{{ $venue->shortDescription ?? '—' }}</span>
            </li>
            <li class="mb-3">
                Адрес:
                <span class="fw-bold">{{ $venue->rawAddress ?? '—' }}</span>
            </li>
        </ul>

        <div class="d-flex gap-2">
            <a href="{{ route('account.venues') }}" class="btn btn-outline-secondary">К списку</a>

            @if ($venue->canEdit)
                <a href="{{ route('account.venues.edit', $venue->routeIdentifier()) }}" class="btn btn-primary">Редактировать</a>
                <a href="{{ route('venues.status', $venue->routeIdentifier()) }}" class="btn btn-outline-primary">Статус</a>
            @endif

            @if ($venue->canEditSchedule)
                <a href="{{ route('account.venues.schedule.edit', $venue->routeIdentifier()) }}" class="btn btn-outline-primary">Расписание</a>
            @endif
        </div>
    @endif
@endsection
