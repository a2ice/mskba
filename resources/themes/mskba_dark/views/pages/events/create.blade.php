@php $title = 'Новое мероприятие'; @endphp

@extends('theme::layouts.section-sidebar', [
    'title' => $title,
    'sectionId' => 'events',
    'sectionClass' => 'events-section',
    'contentTitle' => $title,
    'contentSubtitle' => 'Выберите площадку и время внутри её расписания.',
    'sidebarLabel' => 'Навигация мероприятий',
])

@section('section-sidebar')
    <div class="section-sidebar-block">
        <h2 class="section-sidebar-block__title">Мероприятия</h2>
        <ul class="sidebar-nav nav flex-column">
            <li class="nav-item"><a class="nav-link" href="{{ route('events.index') }}">Все мероприятия</a></li>
            <li class="nav-item active"><a class="nav-link active" href="{{ route('events.create') }}">Создать</a></li>
        </ul>
    </div>
@endsection

@section('section-content')
    @if(session('error')) <div class="alert alert-danger mb-3">{{ session('error') }}</div> @endif
    @if($venues->isEmpty())
        <div class="alert alert-info">Нет доступных площадок с настроенным расписанием.</div>
    @else
        <form method="POST" action="{{ route('events.store') }}">
            @csrf
            <div class="form-group field mb-3">
                <label class="form-label" for="eventTitle">Название</label>
                <input id="eventTitle" class="form-control @error('title') is-invalid @enderror" name="title" value="{{ old('title') }}" maxlength="150" required>
                @error('title') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="form-group field mb-3">
                <label class="form-label" for="eventType">Тип</label>
                <select id="eventType" class="form-select @error('type') is-invalid @enderror" name="type" required>
                    @foreach($types as $type)<option value="{{ $type->value }}" @selected(old('type') === $type->value)>{{ $type->label() }}</option>@endforeach
                </select>
            </div>
            <div class="form-group field mb-3">
                <label class="form-label" for="eventVenue">Площадка</label>
                <select id="eventVenue" class="form-select @error('venue_id') is-invalid @enderror" name="venue_id" required>
                    <option value="">Выберите площадку</option>
                    @foreach($venues as $venue)<option value="{{ $venue->id }}" @selected((string) old('venue_id') === (string) $venue->id)>{{ $venue->name }}{{ $venue->raw_address ? ' — '.$venue->raw_address : '' }}</option>@endforeach
                </select>
                @error('venue_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="row g-3 mb-3">
                <div class="col-md-6 form-group field"><label class="form-label" for="eventStartsAt">Начало</label><input id="eventStartsAt" type="datetime-local" class="form-control @error('starts_at') is-invalid @enderror" name="starts_at" value="{{ old('starts_at') }}" required>@error('starts_at') <div class="invalid-feedback">{{ $message }}</div> @enderror</div>
                <div class="col-md-6 form-group field"><label class="form-label" for="eventEndsAt">Окончание</label><input id="eventEndsAt" type="datetime-local" class="form-control @error('ends_at') is-invalid @enderror" name="ends_at" value="{{ old('ends_at') }}" required>@error('ends_at') <div class="invalid-feedback">{{ $message }}</div> @enderror</div>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-md-6 form-group field"><label class="form-label" for="eventCapacity">Количество участников</label><input id="eventCapacity" type="number" min="2" max="500" class="form-control @error('max_participants') is-invalid @enderror" name="max_participants" value="{{ old('max_participants') }}" placeholder="Без ограничения">@error('max_participants') <div class="invalid-feedback">{{ $message }}</div> @enderror</div>
                <div class="col-md-6 form-group field"><label class="form-label" for="eventVisibility">Доступ</label><select id="eventVisibility" class="form-select" name="visibility">@foreach($visibilities as $visibility)<option value="{{ $visibility->value }}" @selected(old('visibility', 'public') === $visibility->value)>{{ $visibility->label() }}</option>@endforeach</select></div>
            </div>
            <div class="form-group field mb-4"><label class="form-label" for="eventDescription">Описание</label><textarea id="eventDescription" class="form-control @error('description') is-invalid @enderror" name="description" rows="5" maxlength="5000">{{ old('description') }}</textarea>@error('description') <div class="invalid-feedback">{{ $message }}</div> @enderror</div>
            <button class="btn btn--primary" type="submit">Создать мероприятие</button>
        </form>
    @endif
@endsection
