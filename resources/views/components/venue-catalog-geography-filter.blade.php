@php
    $appGeography = $geographyTheme === 'app';
@endphp
<label @unless($appGeography) class="default-category-filter__field" @endunless>
    <span>Город</span>
    @if($appGeography)<span class="select-control">@endif
    <select name="city_id" @unless($appGeography) class="form-select" @endunless>
        <option value="">Все города</option>
        @foreach($geographyCities as $city)
            <option value="{{ $city->id }}" @selected((int) ($filters['city_id'] ?? 0) === $city->id)>{{ $city->name }}</option>
        @endforeach
    </select>
    @if($appGeography)<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></span>@endif
</label>
<label @unless($appGeography) class="default-category-filter__field" @endunless>
    <span>Округ или район</span>
    @if($appGeography)<span class="select-control">@endif
    <select name="district_id" @unless($appGeography) class="form-select" @endunless>
        <option value="">Все округа и районы</option>
        @foreach($geographyCities as $city)
            @if($city->districts->isNotEmpty())
                <optgroup label="{{ $city->name }}">
                    @foreach($city->districts as $district)
                        <option value="{{ $district->id }}" @selected((int) ($filters['district_id'] ?? 0) === $district->id)>{{ $district->name }}</option>
                    @endforeach
                </optgroup>
            @endif
        @endforeach
    </select>
    @if($appGeography)<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></span>@endif
</label>
