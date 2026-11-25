@php
    $appGeography = $geographyTheme === 'app';
    $selectedDistrictId = (int) ($filters['district_id'] ?? 0);
    $selectedCityId = (int) ($filters['city_id'] ?? 0);
    // Keep existing district-only deep links working and represent their city in the UI.
    if (!$selectedCityId && $selectedDistrictId) {
        $selectedCityId = (int) ($geographyCities->first(fn ($city) => $city->districts->contains('id', $selectedDistrictId))?->id ?? 0);
    }
    $selectedCity = $geographyCities->firstWhere('id', $selectedCityId);
@endphp
<label @unless($appGeography) class="default-category-filter__field" @endunless>
    <span>Город</span>
    @if($appGeography)<span class="select-control">@endif
    <select name="city_id" data-venue-geography-city @unless($appGeography) class="form-select" @endunless>
        <option value="">Все города</option>
        @foreach($geographyCities as $city)
            <option value="{{ $city->id }}" @selected($selectedCityId === (int) $city->id)>{{ $city->name }}</option>
        @endforeach
    </select>
    @if($appGeography)<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></span>@endif
</label>
<div data-venue-geography-district-field @if(!$selectedCity)hidden @endif>
    <label @unless($appGeography) class="default-category-filter__field" @endunless>
        <span>Округ или район</span>
        @if($appGeography)<span class="select-control">@endif
        <select name="district_id" data-venue-geography-district @disabled(!$selectedCity) @unless($appGeography) class="form-select" @endunless>
            <option value="">Все округа и районы</option>
            @foreach($selectedCity?->districts ?? [] as $district)
                <option value="{{ $district->id }}" @selected($selectedDistrictId === (int) $district->id)>{{ $district->name }}</option>
            @endforeach
        </select>
        @if($appGeography)<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></span>@endif
    </label>
</div>
<template data-venue-geography-options>
    @foreach($geographyCities as $city)
        @foreach($city->districts as $district)
            <option data-city-id="{{ $city->id }}" value="{{ $district->id }}">{{ $district->name }}</option>
        @endforeach
    @endforeach
</template>
