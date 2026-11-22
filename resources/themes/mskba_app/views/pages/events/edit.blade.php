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
            @if(!$event->starts_at)
                <p>Дата пока не определена. Её можно указать на странице мероприятия.</p>
            @elseif($canReschedule)
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
                @if($event->proposed_time_options !== null)
                    @php($hallOptions = $venues->mapWithKeys(fn ($place) => [(string) $place->id => $place->courts->map(fn ($hall) => ['id' => $hall->id, 'name' => $hall->name])->values()]))
                    <div class="field-group">
                        <label for="eventCourt">Зал</label>
                        <span class="select-control"><select id="eventCourt" name="venue_court_id" data-court-options='@json($hallOptions, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)' data-selected="{{ old('venue_court_id', $event->venue_court_id) }}">
                            <option value="">Любой зал</option>
                            @foreach($event->venue->courts as $hall)<option value="{{ $hall->id }}" @selected((int)old('venue_court_id', $event->venue_court_id) === $hall->id)>{{ $hall->name }}</option>@endforeach
                        </select><svg viewBox="0 0 24 24" aria-hidden="true"><use href="#chevron-down"/></svg></span>
                    </div>
                    @push('scripts')
                    <script type="module">
                    const venue=document.getElementById('eventVenue'),hall=document.getElementById('eventCourt');
                    if(venue&&hall){const options=JSON.parse(hall.dataset.courtOptions);const fill=selected=>{hall.replaceChildren(new Option('Любой зал',''));for(const item of options[venue.value]||[])hall.add(new Option(item.name,String(item.id)));if([...hall.options].some(o=>o.value===selected))hall.value=selected;};fill(hall.dataset.selected);venue.addEventListener('change',()=>fill(''));}
                    </script>
                    @endpush
                @endif
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
                @include('components.event-time-only-fields')

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
            @include('components.event-time-comment-fields', ['timeCommentEvent' => $event])
            @include('components.event-participation-pricing-fields', ['pricingEvent' => $event])
            <div class="field-group">
                <label for="eventDescription">Описание</label>
                <textarea id="eventDescription" name="description" rows="5" maxlength="5000">{{ old('description', $event->description) }}</textarea>
            </div>
            @if($event->canManageFeedback(auth()->user())) @include('components.event-feedback-fields', ['feedbackEvent'=>$event]) @endif
            <div class="app-rental-pricing__actions">
                <button class="button primary" type="submit">Сохранить</button>
                <a class="button secondary" href="{{ route('events.show', $event->routeIdentifier()) }}">Назад</a>
            </div>
        </form>
    </div>
</section>
@endsection
