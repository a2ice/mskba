@php
    $types = $types ?? [];
    $action = $action ?? route('venues.store');
    $cancelUrl = $cancelUrl ?? route('venues');
    $method = $method ?? 'POST';
    $submitLabel = $submitLabel ?? 'Добавить';
    $compactCreate = $compactCreate ?? false;
    $venue = $venue ?? null;
    $venueRevision = $venueRevision ?? null;
    $revisionPayload = $venueRevision?->payload ?? [];
    $revisionDetails = is_array($revisionPayload['details'] ?? null) ? $revisionPayload['details'] : [];
    $revisionLocation = is_array($revisionPayload['location'] ?? null) ? $revisionPayload['location'] : [];
    $venueAddress = $venue?->location?->address;
    $selectedMetroIds = collect(old(
        'location.metro_station_ids',
        $revisionLocation['metro_station_ids'] ?? $venue?->location?->metroStations?->pluck('id')->all() ?? [],
    ))
        ->map(fn ($id) => (string) $id)
        ->all();
@endphp

<form method="POST" action="{{ $action }}">
    @csrf
    @if(strtoupper($method) !== 'POST')
        @method($method)
    @endif

    <div class="mb-3">
        <label for="venueName" class="form-label">Название</label>
        <input
            id="venueName"
            type="text"
            name="name"
            class="form-control @error('name') is-invalid @enderror"
            value="{{ old('name', $revisionDetails['name'] ?? $venue?->name) }}"
            autofocus
            required
        >
        @error('name')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="mb-3">
        <label for="venueType" class="form-label">Тип площадки</label>
        <select
            id="venueType"
            name="type"
            class="form-select predictive_select @error('type') is-invalid @enderror"
            required
        >
            <option value="">Выберите тип</option>
            @foreach ($types as $type)
                <option value="{{ $type->value }}" @selected(old('type', $revisionDetails['type'] ?? $venue?->type?->value) === $type->value)>
                    {{ $type->label() }}
                </option>
            @endforeach
        </select>
        @error('type')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="mb-3 @if($compactCreate) d-none @endif">
        @unless($compactCreate)
        <label for="metro_station" class="form-label">Ближайшее метро</label>
        @endunless
        <select
            id="metro_station"
            name="location[metro_station_ids][]"
            class="form-select metro_select @error('location.metro_station_ids') is-invalid @enderror @error('location.metro_station_ids.*') is-invalid @enderror"
            multiple
            data-address-metro-select
        >
            @foreach ($metros ?? [] as $metro)
                <option
                    value="{{ $metro->id }}"
                    data-line-name="{{ $metro->lineName }}"
                    data-line-color="{{ $metro->lineColor ?? '#666666' }}"
                    @selected(in_array((string) $metro->id, $selectedMetroIds, true))
                >
                    {{ $metro->name }}@if ($metro->lineName) ({{ $metro->lineName }})@endif
                </option>
            @endforeach
        </select>
        @unless($compactCreate)
            @error('location.metro_station_ids')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
            @error('location.metro_station_ids.*')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        @endunless
    </div>

    <div
        class="mb-3 address-suggest"
        data-address-suggest
        data-address-proximity-url="{{ route('venues.proximity-check') }}"
        @if($venue) data-address-proximity-except-venue-id="{{ $venue->id }}" @endif
    >
        <label for="venueRawAddress" class="form-label">Адрес</label>

        <div class="address-suggest__input-wrap">
            <input
                id="venueRawAddress"
                type="text"
                name="location[raw_address]"
                class="form-control input-predictive @error('location.raw_address') is-invalid @enderror"
                value="{{ old('location.raw_address', old('raw_address', $revisionLocation['raw_address'] ?? $venueAddress?->full_address ?? $venue?->raw_address)) }}"
                placeholder="Например: Москва, ул. Летниковская, 12"
                autocomplete="off"
                required
                data-address-suggest-input
                data-address-suggest-url="{{ route('integrations.address-suggest') }}"
            >
            <button class="address-suggest__control" type="button" aria-label="Очистить адрес" data-address-clear hidden></button>
            <div class="address-suggest__list d-none" data-address-suggest-list></div>
        </div>

        <div class="address-suggest__location-row">
            <button
                class="address-suggest__location-button"
                type="button"
                data-address-current-location
                data-address-reverse-url="{{ route('integrations.address-reverse') }}"
            >
                Я на площадке
            </button>
            <span class="address-suggest__proximity-warning" role="status" data-address-proximity-warning hidden></span>
        </div>

        <input type="hidden" name="location[address_selected]" value="{{ old('location.address_selected', ($revisionLocation !== [] || $venueAddress !== null) ? '1' : '') }}" data-address-selected>
        <input type="hidden" name="location[city]" value="{{ old('location.city', $revisionLocation['city'] ?? $venueAddress?->city) }}" data-address-city>
        <input type="hidden" name="location[street]" value="{{ old('location.street', $revisionLocation['street'] ?? $venueAddress?->street) }}" data-address-street>
        <input type="hidden" name="location[building]" value="{{ old('location.building', $revisionLocation['building'] ?? $venueAddress?->building) }}" data-address-building>
        <input type="hidden" name="location[postal_code]" value="{{ old('location.postal_code', $revisionLocation['postal_code'] ?? $venueAddress?->postal_code) }}" data-address-postal-code>
        <input type="hidden" name="location[latitude]" value="{{ old('location.latitude', $revisionLocation['latitude'] ?? $venueAddress?->latitude) }}" data-address-latitude>
        <input type="hidden" name="location[longitude]" value="{{ old('location.longitude', $revisionLocation['longitude'] ?? $venueAddress?->longitude) }}" data-address-longitude>

        <div class="address-suggest__message text-danger d-none" data-address-suggest-error></div>

        @error('location.raw_address')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    @if($compactCreate)
        <div class="venue-form__metro-summary">
            <span>Ближайшее метро</span>
            <strong data-address-metro-summary>Подставится после выбора адреса</strong>
        </div>
    @endif

    @unless($compactCreate)
    <div class="mb-3">
        <label for="venueShortDescription" class="form-label">Краткое описание</label>
        <textarea
            id="venueShortDescription"
            name="short_description"
            class="form-control @error('short_description') is-invalid @enderror"
            rows="2"
            maxlength="500"
        >{{ old('short_description', $revisionDetails['short_description'] ?? $venue?->short_description) }}</textarea>
        @error('short_description')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="mb-3">
        <label for="venueFullDescription" class="form-label">Полное описание</label>
        <textarea
            id="venueFullDescription"
            name="full_description"
            class="form-control @error('full_description') is-invalid @enderror"
            rows="6"
            maxlength="10000"
        >{{ old('full_description', $revisionDetails['full_description'] ?? $venue?->full_description) }}</textarea>
        @error('full_description')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
    <div class="mb-3">
        <label for="venueTags" class="form-label">Теги</label>
        <input
            id="venueTags"
            type="text"
            name="tags"
            class="form-control @error('tags') is-invalid @enderror"
            value="{{ old('tags', isset($revisionPayload['tags']) && is_array($revisionPayload['tags']) ? implode(', ', $revisionPayload['tags']) : $venue?->tags?->pluck('name')->implode(', ')) }}"
            maxlength="1000"
            placeholder="Например: круглосуточно, крытая, бесплатная"
        >
        <div class="form-text">Разделяйте теги запятыми.</div>
        @error('tags')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
    @endunless

    <div class="d-flex flex-wrap gap-3">
        <button type="submit" class="btn btn--primary btn--sm">{{ $submitLabel }}</button>
        <a href="{{ $cancelUrl }}" class="btn btn--secondary btn--sm">Отмена</a>
    </div>
</form>
