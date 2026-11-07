<section class="panel app-venue-editor__panel">
    <h2>Адрес площадки</h2>
    <div class="app-venue-editor__grid">
        <label class="app-venue-editor__field app-venue-editor__field--full">Полный адрес
            <input type="text" value="{{ $address?->full_address ?? $venue->raw_address }}" disabled>
        </label>
        @foreach([
            'Город' => $address?->city,
            'Улица' => $address?->street,
            'Дом / корпус' => $address?->building,
            'Индекс' => $address?->postal_code,
            'Широта' => $address?->latitude,
            'Долгота' => $address?->longitude,
        ] as $label => $value)
            <label class="app-venue-editor__field">{{ $label }}
                <input type="text" value="{{ $value }}" disabled>
            </label>
        @endforeach
    </div>
</section>
<form action="{{ route('account.venues.metro.update', $route) }}" method="POST" class="app-venue-editor__form">
    @csrf @method('PUT')
    <section class="panel app-venue-editor__panel">
        <h2>Станции метро</h2>
        @include('theme::pages.venues.partials.editor-metro-picker', [
            'metroField' => 'metro_station_ids[]',
            'selectedMetroIds' => old('metro_station_ids', $location['metro_station_ids'] ?? []),
        ])
        <div class="app-venue-editor__actions">
            <button class="button primary" type="submit" @disabled($pending)>Сохранить станции метро</button>
        </div>
    </section>
</form>
