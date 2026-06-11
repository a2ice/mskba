@php
    $types = $types ?? [];
    $action = $action ?? route('venues.store');
    $cancelUrl = $cancelUrl ?? route('venues');
@endphp

<form method="POST" action="{{ $action }}">
    @csrf

    <div class="mb-3">
        <label for="venueName" class="form-label">Название</label>
        <input
            id="venueName"
            type="text"
            name="name"
            class="form-control @error('name') is-invalid @enderror"
            value="{{ old('name') }}"
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
                <option value="{{ $type->value }}" @selected(old('type') === $type->value)>
                    {{ $type->label() }}
                </option>
            @endforeach
        </select>
        @error('type')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="mb-3">
        <label for="metro_station" class="form-label">Ближайшее метро</label>
        <select
            id="metro_station"
            name="location[metro_station_ids][]"
            class="form-select metro_select @error('location.metro_station_ids') is-invalid @enderror @error('location.metro_station_ids.*') is-invalid @enderror"
            multiple
            data-address-metro-select
        >
            @php
                $selectedMetroIds = collect(old('location.metro_station_ids', []))
                    ->map(fn ($id) => (string) $id)
                    ->all();
            @endphp
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
        @error('location.metro_station_ids')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
        @error('location.metro_station_ids.*')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="mb-3 address-suggest" data-address-suggest>
        <label for="venueRawAddress" class="form-label">Адрес</label>

        <input
            id="venueRawAddress"
            type="text"
            name="location[raw_address]"
            class="form-control input-predictive @error('location.raw_address') is-invalid @enderror"
            value="{{ old('location.raw_address', old('raw_address')) }}"
            placeholder="Например: Москва, ул. Летниковская, 12"
            autocomplete="off"
            data-address-suggest-input
            data-address-suggest-url="{{ route('integrations.address-suggest') }}"
        >

        <input type="hidden" name="location[city]" value="{{ old('location.city') }}" data-address-city>
        <input type="hidden" name="location[street]" value="{{ old('location.street') }}" data-address-street>
        <input type="hidden" name="location[building]" value="{{ old('location.building') }}" data-address-building>
        <input type="hidden" name="location[postal_code]" value="{{ old('location.postal_code') }}" data-address-postal-code>
        <input type="hidden" name="location[latitude]" value="{{ old('location.latitude') }}" data-address-latitude>
        <input type="hidden" name="location[longitude]" value="{{ old('location.longitude') }}" data-address-longitude>

        <div class="address-suggest__list d-none" data-address-suggest-list></div>
        <div class="address-suggest__message text-danger d-none" data-address-suggest-error></div>

        @error('location.raw_address')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="mb-3">
        <label for="venueDescription" class="form-label">Описание</label>
        <textarea
            id="venueDescription"
            name="description"
            class="form-control @error('description') is-invalid @enderror"
            rows="2"
        >{{ old('description') }}</textarea>
        @error('description')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="d-flex flex-wrap gap-3">
        <button type="submit" class="btn btn--primary btn--sm">Добавить</button>
        <a href="{{ $cancelUrl }}" class="btn btn--secondary btn--sm">Отмена</a>
    </div>
</form>
