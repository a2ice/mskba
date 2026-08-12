@php
    $facilityPayload = is_array($revisionPayload['facilities'] ?? null) ? $revisionPayload['facilities'] : [];
    $storedCharacteristic = \App\Modules\Venue\Domain\Models\VenueCharacteristic::query()
        ->where('venue_id', $venue?->id)
        ->first();
    $savedCharacteristics = is_array($facilityPayload['characteristics'] ?? null)
        ? $facilityPayload['characteristics']
        : [
            'hoops_count' => $storedCharacteristic?->hoops_count,
            'hoops_condition' => $storedCharacteristic?->hoops_condition,
            'surface_condition' => $storedCharacteristic?->surface_condition,
            'marking_condition' => $storedCharacteristic?->marking_condition?->value
                ?? $storedCharacteristic?->first_hoop_marking?->value,
        ];
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
            <legend><i class="ti ti-basketball-hoop" aria-hidden="true"></i> <span title="Количество колец определяет число игровых зон: при двух кольцах можно отдельно бронировать половины A и B." data-tooltip-icon>Количество колец</span></legend>
            <div class="venue-facilities-editor__segments">
                @foreach([1, 2] as $count)
                    <label>
                        <input type="radio" name="characteristics[hoops_count]" value="{{ $count }}" @checked((int) old('characteristics.hoops_count', $savedCharacteristics['hoops_count'] ?? 0) === $count)>
                        <span>{{ $count }}</span>
                    </label>
                @endforeach
            </div>
            @error('characteristics.hoops_count')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </fieldset>

        @foreach(['hoops_condition' => 'Состояние колец', 'surface_condition' => 'Состояние покрытия'] as $field => $label)
            <fieldset class="venue-facilities-editor__control">
                <legend>{{ $label }}</legend>
                <div class="venue-facilities-editor__rating" aria-label="{{ $label }} по пятибалльной шкале">
                    @foreach(range(1, 5) as $rating)
                        <label title="{{ [1 => 'Очень плохое', 2 => 'Плохое', 3 => 'Удовлетворительное', 4 => 'Хорошее', 5 => 'Отличное'][$rating] }}" data-tooltip-variant="title" data-tooltip-icon>
                            <input type="radio" name="characteristics[{{ $field }}]" value="{{ $rating }}" @checked((int) old("characteristics.$field", $savedCharacteristics[$field] ?? 0) === $rating)>
                            <span>{{ $rating }}</span>
                        </label>
                    @endforeach
                </div>
                @error("characteristics.$field")<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </fieldset>
        @endforeach
    </div>

    <fieldset class="venue-facilities-editor__control venue-facilities-editor__marking">
        <legend>Разметка</legend>
        <div class="venue-facilities-editor__segments venue-facilities-editor__segments--wide">
            @foreach($markingOptions as $option)
                <label>
                    <input type="radio" name="characteristics[marking_condition]" value="{{ $option->value }}" @checked(old('characteristics.marking_condition', $savedCharacteristics['marking_condition'] ?? $savedCharacteristics['first_hoop_marking'] ?? null) === $option->value)>
                    <span>{{ $option->label() }}</span>
                </label>
            @endforeach
        </div>
        @error('characteristics.marking_condition')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
    </fieldset>

    <div class="venue-facilities-editor__heading venue-facilities-editor__heading--amenities">
        <div>
            <h2>Оснащение и удобства</h2>
            <p>Набор автоматически меняется в зависимости от выбранного типа площадки.</p>
        </div>
    </div>

    <div class="venue-facilities-editor__amenities">
        @foreach($amenities as $amenity)
            <label class="venue-facilities-editor__amenity" data-amenity-scope="{{ $amenity->applies_to }}">
                <input type="checkbox" name="amenity_ids[]" value="{{ $amenity->id }}" @checked(in_array((int) $amenity->id, $selectedAmenityIds, true))>
                <span class="venue-facilities-editor__amenity-icon" aria-hidden="true"><i class="ti {{ $amenity->icon ?: 'ti-check' }}"></i></span>
                <span>{{ $amenity->name }}</span>
            </label>
        @endforeach
    </div>
    @error('amenity_ids')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
    @error('amenity_ids.*')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
</section>

<style>
    .venue-facilities-form{margin-top:28px;padding-top:28px;border-top:1px solid var(--line)}
    .venue-facilities-editor{display:grid;gap:22px;margin-bottom:22px}
    .venue-facilities-editor__heading h2{margin:0 0 6px;font:700 22px/1.2 var(--font-display)}
    .venue-facilities-editor__heading p{margin:0;color:var(--muted);font-size:13px;line-height:1.5}
    .venue-facilities-editor__heading--amenities{padding-top:5px}
    .venue-facilities-editor__grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}
    .venue-facilities-editor__control{min-width:0;margin:0;padding:16px;border:1px solid var(--line);border-radius:var(--radius-control);background:var(--surface-raised)}
    .venue-facilities-editor__control legend{float:none;width:auto;margin:0 0 12px;color:var(--text);font-size:13px;font-weight:800}
    .venue-facilities-editor__control legend i{margin-right:7px;color:var(--accent-text);font-size:18px;vertical-align:-2px}
    .venue-facilities-editor__marking{max-width:560px}
    .venue-facilities-editor__segments,.venue-facilities-editor__rating{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px}
    .venue-facilities-editor__segments--wide{grid-template-columns:repeat(3,minmax(0,1fr))}
    .venue-facilities-editor__rating{grid-template-columns:repeat(5,minmax(0,1fr))}
    .venue-facilities-editor__segments label,.venue-facilities-editor__rating label{margin:0;cursor:pointer}
    .venue-facilities-editor__segments input,.venue-facilities-editor__rating input,.venue-facilities-editor__amenity input{position:absolute;opacity:0;pointer-events:none}
    .venue-facilities-editor__segments span,.venue-facilities-editor__rating span{display:grid;min-height:40px;place-items:center;padding:7px;border:1px solid var(--field-border);border-radius:10px;background:var(--field);color:var(--muted);font-size:12px;font-weight:800;text-align:center;transition:150ms ease}
    .venue-facilities-editor__segments input:checked+span,.venue-facilities-editor__rating input:checked+span{border-color:var(--accent-hover);background:var(--accent-light);color:#fff}
    .venue-facilities-editor__amenities{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
    .venue-facilities-editor__amenity{display:flex;min-height:54px;align-items:center;gap:11px;padding:11px 13px;border:1px solid var(--line);border-radius:12px;background:var(--surface-raised);color:var(--muted);font-size:13px;font-weight:700;cursor:pointer;transition:150ms ease}
    .venue-facilities-editor__amenity:has(input:checked){border-color:rgba(255,139,47,.7);background:var(--accent-light);color:#fff}
    .venue-facilities-editor__amenity-icon{display:grid;width:30px;height:30px;flex:0 0 30px;place-items:center;border-radius:9px;background:rgba(255,255,255,.07);font-size:17px}
    [data-amenity-scope][hidden]{display:none}
    @media(max-width:900px){.venue-facilities-editor__grid,.venue-facilities-editor__amenities{grid-template-columns:repeat(2,minmax(0,1fr))}}
    @media(max-width:620px){.venue-facilities-editor__grid,.venue-facilities-editor__amenities{grid-template-columns:1fr}.venue-facilities-editor__marking{max-width:none}}
</style>

<script>
    (() => {
        const editor = document.querySelector('[data-venue-facilities-editor]');
        const typeSelect = document.getElementById('venueType');
        const facilitiesForm = editor?.closest('form');
        const hiddenType = facilitiesForm?.querySelector('input[name="type"]');
        if (!editor || !typeSelect) return;

        const refresh = () => {
            const group = typeSelect.value === 'street_court' ? 'outdoor' : 'indoor';
            if (hiddenType) hiddenType.value = typeSelect.value;
            editor.querySelectorAll('[data-amenity-scope]').forEach((item) => {
                const visible = ['all', group].includes(item.dataset.amenityScope);
                item.hidden = !visible;
                if (!visible) item.querySelector('input').checked = false;
            });
        };

        typeSelect.addEventListener('change', refresh);
        refresh();
    })();
</script>
