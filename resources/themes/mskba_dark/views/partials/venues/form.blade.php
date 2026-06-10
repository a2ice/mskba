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
            class="form-select @error('type') is-invalid @enderror"
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
        <label for="" class="form-label">Ближайшее метро</label>
        <select
            id=""
            name="nearest_metro"
            class="form-select @error('nearest_metro') is-invalid @enderror"
        >
            <option value="">Выберите станцию</option>
            @foreach ($metros ?? [] as $metro)
                <option value="{{ $metro->id }}" @selected(old('nearest_metro') === $metro->id)>
                    {{ $metro->name }}
                </option>
            @endforeach
        </select>
        @error('nearest_metro')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror        
    </div>  

    <div class="mb-3">
        <label for="venueRawAddress" class="form-label">Адрес</label>
        <textarea
            id="venueRawAddress"
            name="raw_address"
            class="form-control @error('raw_address') is-invalid @enderror"
            rows="1"
            placeholder="Например: Москва, ул. Летниковская, 12"
        >{{ old('raw_address') }}</textarea>
        @error('raw_address')
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
