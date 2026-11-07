@php
    $metroField = $metroField ?? 'location[metro_station_ids][]';
    $selectedMetroIds = collect($selectedMetroIds ?? [])
        ->map(static fn ($id): string => (string) $id)->all();
@endphp
<div class="app-venue-metro" data-venue-metro-picker>
    <label class="app-venue-editor__field" for="venue-metro-search">Станции метро</label>
    <div class="app-venue-metro__search">
        <input id="venue-metro-search" type="search" placeholder="Начните вводить станцию или линию"
            autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false"
            aria-controls="venue-metro-options" data-venue-metro-search @disabled($pending)>
        <div class="app-venue-metro__options" role="listbox" id="venue-metro-options" data-venue-metro-options hidden></div>
    </div>
    <div class="app-venue-metro__chips" data-venue-metro-chips aria-label="Выбранные станции метро"></div>
    <select name="{{ $metroField }}" multiple data-venue-metro-select hidden
        aria-label="Выбранные станции метро" @disabled($pending)>
        @foreach($metros as $metro)
            <option value="{{ $metro->id }}"
                data-line-name="{{ $metro->lineName }}"
                data-line-color="{{ $metro->lineColor ?? '#667788' }}"
                @selected(in_array((string) $metro->id, $selectedMetroIds, true))>
                {{ $metro->name }}@if($metro->lineName) ({{ $metro->lineName }}) @endif
            </option>
        @endforeach
    </select>
</div>
