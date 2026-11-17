@php
    $title = 'Редактирование мероприятия';
    $timezone = $event->venue->schedule?->timezone ?: config('app.timezone', 'Europe/Moscow');
@endphp
@extends('theme::layouts.app', ['title' => $title])
@section('content')
<section class="app-account-section">
    <div class="container app-account-section__inner">
        <header class="app-account-section__heading">
            <p><a href="{{ route('events.show', $event->routeIdentifier()) }}">← К мероприятию</a></p>
            <h1>{{ $title }}</h1>
            <p>{{ $event->title }}</p>
        </header>
        @if(session('error'))<p role="alert">{{ session('error') }}</p>@endif
        @if($errors->any())
            <section class="panel" role="alert">
                @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
            </section>
        @endif
        <form class="panel" method="POST" action="{{ route('events.update', $event->routeIdentifier()) }}">
            @csrf @method('PUT')
            <div class="field-group">
                <label for="eventTitle">Название</label>
                <input id="eventTitle" name="title" value="{{ old('title', $event->title) }}" maxlength="150" required>
            </div>
            @if($canReschedule)
                <p>Место и время можно пересмотреть. Предзаявка не резервирует зал; после изменения участники должны заново подтвердить участие.</p>
                <div class="field-group">
                    <label for="eventVenue">Площадка</label>
                    <span class="select-control">
                        <select id="eventVenue" name="venue_id" required>
                            @foreach($venues as $venue)
                                <option value="{{ $venue->id }}" @selected((string) old('venue_id', $event->venue_id) === (string) $venue->id)>{{ $venue->name }}{{ $venue->raw_address ? ' — '.$venue->raw_address : '' }}</option>
                            @endforeach
                        </select>
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                    </span>
                </div>
                <div class="field-group">
                    <label for="eventStartsAt">Начало</label>
                    <input id="eventStartsAt" type="datetime-local" name="starts_at"
                        value="{{ old('starts_at', $event->starts_at->setTimezone($timezone)->format('Y-m-d\TH:i')) }}" required>
                </div>
                <div class="field-group">
                    <label for="eventDuration">Длительность</label>
                    <span class="select-control">
                        <select id="eventDuration" name="duration_minutes" required>
                            @foreach(collect($durationOptions)->when(!in_array($currentDuration, $durationOptions, true),
                                fn ($durations) => $durations->push($currentDuration))->sort()->values() as $minutes)
                                <option value="{{ $minutes }}" @selected((int) old('duration_minutes', $currentDuration) === $minutes)>{{ $minutes }} мин.</option>
                            @endforeach
                        </select>
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                    </span>
                </div>
                @if($event->booking_deferred)
                    <div class="field-group">
                        <label for="eventRevisionReason">Причина изменения (по желанию)</label>
                        <input id="eventRevisionReason" name="revision_reason" value="{{ old('revision_reason') }}" maxlength="255" placeholder="Участникам станет понятнее причина переноса">
                    </div>
                @endif
            @else
                <p>Для площадок с оплатой или подтверждением бронирования после подачи заявки изменение места и времени доступно через отмену или согласованный перенос брони.</p>
                <input type="hidden" name="venue_id" value="{{ $event->venue_id }}">
                <input type="hidden" name="starts_at" value="{{ $event->starts_at->setTimezone($timezone)->format('Y-m-d\TH:i') }}">
                <input type="hidden" name="duration_minutes" value="{{ $currentDuration }}">
            @endif
            <div class="field-group">
                <label for="eventType">Тип мероприятия</label>
                <span class="select-control">
                    <select id="eventType" name="type" required>
                        @foreach($types as $type)
                            <option value="{{ $type->value }}" @selected(old('type', $event->type->value) === $type->value)>{{ $type->label() }}</option>
                        @endforeach
                    </select>
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                </span>
            </div>
            <div class="field-group">
                <label for="eventVisibility">Видимость</label>
                <span class="select-control">
                    <select id="eventVisibility" name="visibility" required>
                        @foreach($visibilities as $visibility)
                            <option value="{{ $visibility->value }}" @selected(old('visibility', $event->visibility->value) === $visibility->value)>{{ $visibility->label() }}</option>
                        @endforeach
                    </select>
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                </span>
            </div>
            <div class="field-group">
                <label for="eventCapacity">Максимальное число участников</label>
                <input id="eventCapacity" type="number" min="2" max="500" name="max_participants"
                    value="{{ old('max_participants', $event->max_participants) }}" placeholder="Без ограничения">
            </div>
            <div class="field-group">
                <label for="eventDescription">Описание</label>
                <textarea id="eventDescription" name="description" rows="5" maxlength="5000">{{ old('description', $event->description) }}</textarea>
            </div>
            <div class="app-rental-pricing__actions">
                <button class="button primary" type="submit">Сохранить</button>
                <a class="button secondary" href="{{ route('events.show', $event->routeIdentifier()) }}">Назад</a>
            </div>
        </form>
    </div>
</section>
@endsection
