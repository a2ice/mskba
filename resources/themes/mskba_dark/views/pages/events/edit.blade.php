@php
    $title = 'Редактирование мероприятия';
    $timezone = $event->venue->schedule?->timezone ?: config('app.timezone');
@endphp

@extends('theme::layouts.section-sidebar', [
    'title' => $title,
    'sectionId' => 'events',
    'sectionClass' => 'events-section',
    'contentTitle' => $title,
    'contentSubtitle' => $event->title,
    'sidebarLabel' => 'Навигация мероприятий',
])

@section('section-sidebar')
    <div class="section-sidebar-block">
        <h2 class="section-sidebar-block__title">Управление</h2>
        <ul class="sidebar-nav nav flex-column">
            <li class="nav-item"><a class="nav-link" href="{{ route('events.show', $event->routeIdentifier()) }}">Обзор</a></li>
            <li class="nav-item active"><a class="nav-link active" href="{{ route('events.edit', $event->routeIdentifier()) }}">Редактировать</a></li>
        </ul>
    </div>
@endsection

@section('section-content')
    @if(session('error')) <div class="alert alert-danger mb-3">{{ session('error') }}</div> @endif

    <div class="alert alert-info mb-4">
        Площадка и время не изменяются в этой форме: {{ $event->venue->name }}, {{ $event->starts_at->setTimezone($timezone)->format('d.m.Y H:i') }}–{{ $event->ends_at->setTimezone($timezone)->format('H:i') }}.
    </div>

    <form method="POST" action="{{ route('events.update', $event->routeIdentifier()) }}">
        @csrf @method('PUT')
        <div class="form-group field mb-3">
            <label class="form-label" for="eventTitle">Название</label>
            <input id="eventTitle" class="form-control @error('title') is-invalid @enderror" name="title" value="{{ old('title', $event->title) }}" maxlength="150" required>
            @error('title') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="row g-3 mb-3">
            <div class="col-md-6 form-group field">
                <label class="form-label" for="eventType">Тип</label>
                <select id="eventType" class="form-select @error('type') is-invalid @enderror" name="type" required>
                    @foreach($types as $type)<option value="{{ $type->value }}" @selected(old('type', $event->type->value) === $type->value)>{{ $type->label() }}</option>@endforeach
                </select>
                @error('type') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="col-md-6 form-group field">
                <label class="form-label" for="eventVisibility">Доступ</label>
                <select id="eventVisibility" class="form-select @error('visibility') is-invalid @enderror" name="visibility" required>
                    @foreach($visibilities as $visibility)<option value="{{ $visibility->value }}" @selected(old('visibility', $event->visibility->value) === $visibility->value)>{{ $visibility->label() }}</option>@endforeach
                </select>
                @error('visibility') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
        </div>
        <div class="form-group field mb-3">
            <label class="form-label" for="eventCapacity">Количество участников</label>
            <input id="eventCapacity" type="number" min="2" max="500" class="form-control @error('max_participants') is-invalid @enderror" name="max_participants" value="{{ old('max_participants', $event->max_participants) }}" placeholder="Без ограничения">
            @error('max_participants') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="form-group field mb-4">
            <label class="form-label" for="eventDescription">Описание</label>
            <textarea id="eventDescription" class="form-control @error('description') is-invalid @enderror" name="description" rows="7" maxlength="5000">{{ old('description', $event->description) }}</textarea>
            @error('description') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <button class="btn btn--primary" type="submit">Сохранить</button>
    </form>
@endsection
