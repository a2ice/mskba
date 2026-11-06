@php
    $courtAmenities = $courtAmenities ?? \App\Modules\Venue\Domain\Models\Amenity::query()
        ->where('is_active', true)->where('scope', 'court')->orderBy('sort_order')->get();
    $chosen = array_map('intval', (array) old('amenity_ids',
        $court?->amenities?->pluck('id')->all() ?? []));
@endphp
<fieldset class="court-equipment" data-court-equipment>
    <legend>Оснащение зала / корта</legend>
    <div class="court-equipment__grid">
        @foreach($courtAmenities as $amenity)
            <label data-applies="{{ $amenity->applies_to }}">
                <input type="checkbox" name="amenity_ids[]" value="{{ $amenity->id }}"
                    @checked(in_array((int) $amenity->id, $chosen, true))>
                {{ $amenity->name }}
            </label>
        @endforeach
    </div>
</fieldset>
<style>
.court-equipment{border:1px solid var(--line,#ddd);border-radius:10px;margin:14px 0;padding:12px}
.court-equipment legend{font-size:13px;font-weight:700;float:none}
.court-equipment__grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
.court-equipment__grid label{display:flex;gap:8px;align-items:center;font-size:13px}
.court-equipment__grid label[hidden]{display:none}
@media(max-width:640px){.court-equipment__grid{grid-template-columns:1fr}}
</style>
<script>
(() => {
    document.querySelectorAll('[data-court-equipment]:not([data-bound])').forEach(root => {
        root.dataset.bound = '1';
        const selector = root.closest('form')?.querySelector('select[name="environment"]');
        if (!selector) return;
        const refresh = () => root.querySelectorAll('[data-applies]').forEach(label => {
            const valid = selector.value !== '' &&
                ['all', selector.value].includes(label.dataset.applies);
            label.hidden = !valid;
            if (!valid) label.querySelector('input').checked = false;
        });
        selector.addEventListener('change', refresh);
        refresh();
    });
})();
</script>
