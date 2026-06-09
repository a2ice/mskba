@php
    if(isset($venue)) {
        $title = "{$venue->name}";
    } else {
        $title = 'Ошибка';
        $error_message = isset($error['message']) ? $error['message'] : 'Неизвестная ошибка';
        $title .= " - $error_message";
    }
@endphp

@extends('theme::layouts.section-sidebar', [
    'title' => $title,
    'sectionId' => 'venue',
    'sectionClass' => 'venue-section',
    'contentTitle' => isset($venue) ? $venue->name : 'Площадка',
    'sidebarLabel' => 'Навигация площадки',
])

@section('section-sidebar')
    <div class="section-sidebar-block">
        @include('theme::partials.menu.sidebar', ['page' => 'venues'])
    </div>

    <div class="section-sidebar-block">
        <h2 class="section-sidebar-block__title">Управление</h2>
        @if(!empty($venue) && $venue->canEdit)
            <a href="{{ route('venues.edit', $venue->alias) }}" class="btn btn--secondary btn--sm">Редактировать</a>
        @else
            <p class="section-sidebar-block__text">
                Доступные действия появятся здесь, если у пользователя есть права на управление площадкой.
            </p>
        @endif
    </div>
@endsection

@section('section-content')
    @if(!empty($venue))
        <ul class="list-unstyled mb-4">
            <li class="mb-3">
                Alias:
                <span class="fw-bold">{{ $venue->alias }}</span>
            </li>
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
                <span class="fw-bold">{{ $venue->description ?? '—' }}</span>
            </li>
        </ul>

        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('venues') }}" class="btn btn--primary btn--sm">К списку</a>

            @if ($venue->canEdit)
                <a href="{{ route('venues.edit', $venue->alias) }}" class="btn btn--secondary btn--sm">Редактировать</a>
            @endif

            @if ($venue->canEditSchedule)
                <a href="#" class="btn btn--secondary btn--sm">Расписание</a>
            @endif
        </div>
    @else
        <div class="alert alert-warning" role="alert">
            {{ $error_message }}
        </div>
    @endif
@endsection
