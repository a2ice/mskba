@php
    $facilityPayload = is_array($revisionPayload['facilities'] ?? null) ? $revisionPayload['facilities'] : [];
    $savedCharacteristics = is_array($facilityPayload['characteristics'] ?? null)
        ? $facilityPayload['characteristics']
        : (array) optional(\App\Modules\Venue\Domain\Models\VenueCharacteristic::query()
            ->where('venue_id', $venue?->id)
            ->first())->only([
                'hoops_count',
                'hoops_condition',
                'surface_condition',
                'first_hoop_marking',
                'second_hoop_marking',
            ]);
    $selectedAmenityIds = collect(old(
        'amenity_ids',
        $facilityPayload['amenity_ids'] ?? $venue?->amenities?->pluck('id')->all() ?? [],
    ))->map(fn ($id) => (int) $id)->all();
    $amenities = \App\Modules\Venue\Domain\Models\Amenity::query()
        ->where('is_active', true)
        ->orderBy('sort_order')
        ->orderBy('name')
        ->get();
    $markingOptions = \App\Modules\Venue\Domain\Enums\VenueMarkingConditionEnum::cases();
    $selectedType = old('type', $revisionDetails['type'] ?? $venue?->type?->value);
@endphp

<section class="venue-facilities-editor" data-venue-facilities-editor>
    <div class="venue-facilities-editor__heading">
        <div>
            <h2>Кольца, покрытие и разметка</h2>
            <p>Укажите фактическое состояние площадки. Эти данные помогут игрокам подобрать подходящее место.</p>
        </div>
    </div>

    <div class="venue-facilities-editor__grid">
        <fieldset class="venue-facilities-editor__control">
            <legend>Количество колец</legend>
            <div class="venue-facilities-editor__segments">
                @foreach([1, 2] as $count)
                    <label>
                        <input
                            type="radio"
                            name="characteristics[hoops_count]"
                            value="{{ $count }}"
                            @checked((int) old('characteristics.hoops_count', $savedCharacteristics['hoops_count'] ?? 0) === $count)
                            data-hoops-count
                        >
                        <span>{{ $count }}</span>
                    </label>
                @endforeach
            </div>
            @error('characteristics.hoops_count')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </fieldset>

        @foreach([
            'hoops_condition' => 'Состояние колец',
            'surface_condition' => 'Состояние покрытия',
        ] as $field => $label)
            <fieldset class="venue-facilities-editor__control">
                <legend>{{ $label }}</legend>
                <div class="venue-facilities-editor__rating" aria-label="{{ $label }} по пятибалльной шкале">
                    @foreach(range(1, 5) as $rating)
                        <label title="{{ [1 => 'Очень плохое', 2 => 'Плохое', 3 => 'Удовлетворительное', 4 => 'Хорошее', 5 => 'Отличное'][$rating] }}">
                            <input
                                type="radio"
                                name="characteristics[{{ $field }}]"
                                value="{{ $rating }}"
                                @checked((int) old("characteristics.$field", $savedCharacteristics[$field] ?? 0) === $rating)
                            >
                            <span>{{ $rating }}</span>
                        </label>
                    @endforeach
                </div>
                @error("characteristics.$field")<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </fieldset>
        @endforeach
    </div>

    <div class="venue-facilities-editor__markings">
        @foreach(['first' => 'У первого кольца', 'second' => 'У второго кольца'] as $position => $label)
            <fieldset
                class="venue-facilities-editor__control"
                @if($position === 'second') data-second-hoop-marking @endif
            >
                <legend>Разметка {{ mb_strtolower($label) }}</legend>
                <div class="venue-facilities-editor__segments venue-facilities-editor__segments--wide">
                    @foreach($markingOptions as $option)
                        <label>
                            <input
                                type="radio"
                                name="characteristics[{{ $position }}_hoop_marking]"
                                value="{{ $option->value }}"
                                @checked(old("characteristics.{$position}_hoop_marking", $savedCharacteristics["{$position}_hoop_marking"] ?? null) === $option->value)
                            >
                            <span>{{ $option->label() }}</span>
                        </label>
                    @endforeach
                </div>
                @error("characteristics.{$position}_hoop_marking")<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </fieldset>
        @endforeach
    </div>

    <div class="venue-facilities-editor__heading venue-facilities-editor__heading--amenities">
        <div>
            <h2>Оснащение и удобства</h2>
            <p>Набор автоматически меняется в зависимости от выбранного типа площадки.</p>
        </div>
    </div>

    <div class="venue-facilities-editor__amenities">
        @foreach($amenities as $amenity)
            <label
                class="venue-facilities-editor__amenity"
                data-amenity-scope="{{ $amenity->applies_to }}"
            >
                <input
                    type="checkbox"
                    name="amenity_ids[]"
                    value="{{ $amenity->id }}"
                    @checked(in_array((int) $amenity->id, $selectedAmenityIds, true))
                >
                <span class="venue-facilities-editor__amenity-icon" aria-hidden="true">
                    <i class="ti {{ $amenity->icon ?: 'ti-check' }}"></i>
                </span>
                <span>{{ $amenity->name }}</span>
            </label>
        @endforeach
    </div>
    @error('amenity_ids')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
    @error('amenity_ids.*')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
</section>

<script>
    (() => {
        const editor = document.querySelector('[data-venue-facilities-editor]');
        const typeSelect = document.getElementById('venueType');
        if (!editor || !typeSelect) return;

        const refresh = () => {
            const group = typeSelect.value === 'street_court' ? 'outdoor' : 'indoor';
            editor.querySelectorAll('[data-amenity-scope]').forEach((item) => {
                const visible = ['all', group].includes(item.dataset.amenityScope);
                item.hidden = !visible;
                if (!visible) item.querySelector('input').checked = false;
            });

            const hoopsCount = editor.querySelector('[data-hoops-count]:checked')?.value;
            const second = editor.querySelector('[data-second-hoop-marking]');
            if (second) {
                second.hidden = hoopsCount !== '2';
                second.querySelectorAll('input').forEach((input) => {
                    input.disabled = hoopsCount !== '2';
                    if (hoopsCount !== '2') input.checked = false;
                });
            }
        };

        typeSelect.addEventListener('change', refresh);
        editor.querySelectorAll('[data-hoops-count]').forEach((input) => input.addEventListener('change', refresh));
        refresh();
    })();
</script>
