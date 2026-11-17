@php
    $initialCourts = old('initial_courts', [[
        'name' => 'Игровая зона 1', 'environment' => '',
        'hoops_count' => 2, 'surface_type' => null, 'amenity_ids' => [],
    ]]);
    $courtAmenities = \App\Modules\Venue\Domain\Models\Amenity::query()
        ->where('scope', 'court')->where('is_active', true)->orderBy('sort_order')->get();
    $commonAmenities = \App\Modules\Venue\Domain\Models\Amenity::query()
        ->where('scope', 'venue')->where('is_active', true)->orderBy('sort_order')->get();
    $selectedCommon = array_map('intval', (array) old('amenity_ids', []));
@endphp
<section class="venue-court-creator" data-venue-court-creator>
    <h3>Залы и корты *</h3>
    <p>Нужно добавить хотя бы одну игровую зону. У одной площадки могут быть крытые залы и уличные корты.</p>
    <div data-court-list class="venue-court-creator__list">
        @foreach($initialCourts as $index => $court)
            @include('venues.partials.initial-court-row', ['index' => $index, 'court' => $court, 'courtAmenities' => $courtAmenities])
        @endforeach
    </div>
    <template data-court-template>
        @include('venues.partials.initial-court-row', ['index' => '__IDX__', 'court' => [], 'courtAmenities' => $courtAmenities])
    </template>
    <button type="button" class="venue-court-creator__add" data-court-add>Добавить зал / корт</button>
    @error('initial_courts')<p role="alert">{{ $message }}</p>@enderror
    <fieldset class="venue-court-creator__shared">
        <legend>Общие удобства площадки</legend>
        <div class="venue-court-entry__choices">
            @foreach($commonAmenities as $amenity)
                <label>
                    <input type="checkbox" name="amenity_ids[]" value="{{ $amenity->id }}"
                        @checked(in_array((int) $amenity->id, $selectedCommon, true))>
                    {{ $amenity->name }}
                </label>
            @endforeach
        </div>
    </fieldset>
</section>
<style>
.venue-court-creator{border:1px solid var(--line,#ddd);border-radius:12px;padding:16px;margin:20px 0}
.venue-court-creator h3{font-size:18px;font-weight:750;margin:0 0 6px}
.venue-court-creator p{font-size:13px;color:var(--muted,#777);margin:0 0 12px}
.venue-court-creator__list{display:grid;gap:12px}
.venue-court-entry{border:1px solid var(--line,#ddd);border-radius:10px;min-width:0;padding:14px}
.venue-court-entry legend,.venue-court-creator__shared legend{font-size:14px;font-weight:700;float:none}
.venue-court-entry__grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.venue-court-entry__grid label{display:grid;gap:5px;font-size:13px;font-weight:650}
.venue-court-entry__grid input,.venue-court-entry__grid select{width:100%;min-width:0;padding:10px;border:1px solid var(--field-border,#ccc);border-radius:8px;background:var(--field,#fff);color:var(--text,#222)}
.venue-court-entry__equipment,.venue-court-creator__shared{margin:14px 0 0;padding:10px 0 0;border:0;border-top:1px solid var(--line,#ddd)}
.venue-court-entry__choices{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
.venue-court-entry__choices label{display:flex;align-items:center;gap:7px;font-size:13px}
.venue-court-entry__choices label[hidden]{display:none}
.venue-court-entry__remove,.venue-court-creator__add{padding:8px 12px;margin-top:12px;cursor:pointer;background:var(--surface-raised,#fff);color:var(--text,#222);border:1px solid var(--line,#ddd);border-radius:8px}
@media(max-width:640px){.venue-court-entry__grid,.venue-court-entry__choices{grid-template-columns:1fr}}
</style>
<script>
(() => {
    document.querySelectorAll('[data-venue-court-creator]:not([data-bound])').forEach(root => {
        root.dataset.bound = '1';
        const list = root.querySelector('[data-court-list]');
        const template = root.querySelector('[data-court-template]');
        const add = root.querySelector('[data-court-add]');
        let nextIndex = Math.max(0,...[...list.children].map(row => Number(row.dataset.index)||0)) + 1;
        function refresh() {
            const rows = [...list.querySelectorAll('[data-court-entry]')];
            rows.forEach((row, i) => {
                row.querySelector('[data-court-caption]').textContent = 'Зал / корт ' + (i + 1);
                row.querySelector('[data-court-remove]').hidden = rows.length < 2;
                const env = row.querySelector('[data-court-environment]').value;
                row.querySelectorAll('[data-applicability]').forEach(label => {
                    const valid = env !== '' && ['all',env].includes(label.dataset.applicability);
                    label.hidden = !valid;
                    if (!valid) label.querySelector('input').checked = false;
                });
            });
            add.disabled = rows.length >= 10;
        }
        root.addEventListener('change', e => {
            if(e.target.matches('[data-court-environment]')) refresh();
        });
        root.addEventListener('click', e => {
            const button = e.target.closest('[data-court-remove]');
            if(button) {button.closest('[data-court-entry]').remove();refresh();}
        });
        add.addEventListener('click', () => {
            list.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__IDX__',String(nextIndex++)));
            list.lastElementChild.querySelector('input[name$="[name]"]').value =
                'Игровая зона ' + list.children.length;
            refresh();
        });
        refresh();
    });
})();
</script>
