@php
    $chosenVenueTypes = (array) ($filters['type'] ?? []);
    $venueTypeLabels = collect($types)->filter(fn ($type) => in_array($type->value, $chosenVenueTypes, true))->map(fn ($type) => $type->label())->all();
@endphp
<div class="venue-catalog-type-field{{ ($typeFilterTheme ?? 'app') === 'dark' ? ' default-category-filter__field' : '' }}">
    <span>Типы площадок</span>
    <details data-venue-type-filter>
        <summary data-venue-type-summary>{{ $venueTypeLabels === [] ? 'Все типы' : implode(', ', $venueTypeLabels) }}</summary>
        <div class="venue-catalog-type-options app-venues-catalog__checkbox-group" role="group" aria-label="Типы площадок">
            @foreach($types as $type)
                <label class="check-label">
                    <input type="checkbox" name="type[]" value="{{ $type->value }}" data-venue-type-label="{{ $type->label() }}" @checked(in_array($type->value, $chosenVenueTypes, true))>
                    <span>{{ $type->label() }}</span>
                </label>
            @endforeach
        </div>
    </details>
</div>
