@php
    $formIdPrefix = $formIdPrefix ?? 'event';
    $submitLabel = $submitLabel ?? 'Создать мероприятие';
    $selectedVenue = old('venue_id')
        ? $venues->firstWhere('id', (int) old('venue_id'))
        : null;
@endphp

<form method="POST" action="{{ $formAction }}" data-event-create-form data-current-date="{{ $currentDate }}">
    @csrf
    <div class="form-group field mb-3">
        <label class="form-label" for="{{ $formIdPrefix }}Title">Название</label>
        <input id="{{ $formIdPrefix }}Title" class="form-control @error('title') is-invalid @enderror" name="title" value="{{ old('title', $defaultTitle) }}" maxlength="150" required data-event-title data-generated-title="{{ $defaultTitle }}">
        @error('title') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>
    <div class="form-group field mb-3">
        <label class="form-label" for="{{ $formIdPrefix }}Type">Тип</label>
        <select id="{{ $formIdPrefix }}Type" class="form-select @error('type') is-invalid @enderror" name="type" required>
            @foreach($types as $type)<option value="{{ $type->value }}" data-title-prefix="{{ $type->label() }}" @selected(old('type', $defaultType->value) === $type->value)>{{ $type->label() }}</option>@endforeach
        </select>
    </div>
    <div class="form-group field mb-3">
        @include('theme::partials.venues.predictive-selector', [
            'id' => $formIdPrefix.'Venue',
            'selectedVenue' => $selectedVenue,
            'confirmedOnly' => true,
            'operationalStatus' => 'active',
            'startInput' => '#'.$formIdPrefix.'StartsAt',
            'durationInput' => '#'.$formIdPrefix.'Duration',
            'mapModal' => $formIdPrefix.'-venue-map',
            'showFavorites' => true,
        ])
    </div>
    <div class="row g-3 mb-3">
        <div class="col-md-6 form-group field">
            <label class="form-label" for="{{ $formIdPrefix }}StartsAt">Начало</label>
            <input id="{{ $formIdPrefix }}StartsAt" type="datetime-local" class="form-control @error('starts_at') is-invalid @enderror" name="starts_at" value="{{ old('starts_at', $defaultStartsAt) }}" min="{{ $defaultStartsAt }}" required data-event-start>
            @error('starts_at') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="col-md-6 form-group field">
            <label class="form-label" for="{{ $formIdPrefix }}Duration">Длительность</label>
            <select id="{{ $formIdPrefix }}Duration" class="form-select @error('duration_minutes') is-invalid @enderror" name="duration_minutes" required>
                @foreach($durationOptions as $minutes)
                    @php
                        $hours = $minutes / 60;
                        $durationLabel = $minutes === 30
                            ? '30 минут'
                            : number_format($hours, $minutes % 60 === 0 ? 0 : 1, ',', '').' '.($hours === 1.0 ? 'час' : ($minutes % 60 !== 0 || $hours < 5 ? 'часа' : 'часов'));
                    @endphp
                    <option value="{{ $minutes }}" @selected((int) old('duration_minutes', $defaultDuration) === $minutes)>{{ $durationLabel }}</option>
                @endforeach
            </select>
            @error('duration_minutes') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
    </div>
    <div class="row g-3 mb-3">
        <div class="col-md-6 form-group field">
            <label class="form-label" for="{{ $formIdPrefix }}Capacity">Количество участников</label>
            <input id="{{ $formIdPrefix }}Capacity" type="number" min="2" max="500" class="form-control @error('max_participants') is-invalid @enderror" name="max_participants" value="{{ old('max_participants') }}" placeholder="Без ограничения">
            @error('max_participants') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="col-md-6 form-group field">
            <label class="form-label" for="{{ $formIdPrefix }}Visibility">Доступ</label>
            <select id="{{ $formIdPrefix }}Visibility" class="form-select" name="visibility">
                @foreach($visibilities as $visibility)<option value="{{ $visibility->value }}" @selected(old('visibility', 'public') === $visibility->value)>{{ $visibility->label() }}</option>@endforeach
            </select>
        </div>
    </div>
    <div class="form-group field mb-4">
        <label class="form-label" for="{{ $formIdPrefix }}Description">Описание</label>
        <textarea id="{{ $formIdPrefix }}Description" class="form-control @error('description') is-invalid @enderror" name="description" rows="5" maxlength="5000">{{ old('description', $defaultDescription ?? null) }}</textarea>
        @error('description') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>
    <button
        class="btn btn--primary"
        type="submit"
        @if(!empty($confirmMessage)) onclick='return confirm(@json($confirmMessage))' @endif
    >{{ $submitLabel }}</button>
</form>

@component('theme::partials.modal.layout', ['id' => 'event-favorite-venues'])
    <h2 class="modal_title" id="modal-title-event-favorite-venues">Избранные площадки</h2>
    <p class="modal-description">Функционал находится в разработке.</p>
@endcomponent
