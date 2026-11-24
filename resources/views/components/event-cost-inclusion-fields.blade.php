@php
    $inclusionOptions = \App\Modules\Event\Application\Services\EventParticipationPricing::INCLUSION_LABELS;
    $inclusionItems = $pricingEvent === null
        ? \App\Modules\Event\Application\Services\EventParticipationPricing::defaultInclusions()
        : ($pricingEvent->cost_inclusions ?? []);
    $inclusionOld = old('cost_inclusions_present') !== null;
@endphp
<div class="field-group" data-event-cost-inclusion-fields style="display:grid;gap:6px">
    <strong>Что входит в оплату</strong>
    <input type="hidden" name="cost_inclusions_present" value="1">
    @foreach($inclusionOptions as $key => $label)
        @php
            $prefix = 'cost_inclusion_'.$key;
            $item = $inclusionItems[$key] ?? [];
            $selected = $inclusionOld ? (bool) old($prefix, false) : array_key_exists($key, $inclusionItems);
        @endphp
        <div data-cost-inclusion-row>
            <input type="hidden" name="{{ $prefix }}" value="{{ $selected ? '1' : '0' }}" data-cost-inclusion-value>
            <label class="check-label" style="display:flex;align-items:center;gap:8px">
                <input type="checkbox" data-cost-inclusion-check @checked($selected)> {{ $label }}
            </label>
            <div data-cost-inclusion-details @if(!$selected) hidden @endif>
                @if($key === 'custom')
                    <label class="field-group">
                        <span>Название</span>
                        <input class="form-control" type="text" name="{{ $prefix }}_title" maxlength="100" value="{{ old($prefix.'_title', $item['title'] ?? '') }}" @disabled(!$selected) @required($selected) data-cost-inclusion-title>
                    </label>
                @endif
                <label class="field-group">
                    <span>Цена, руб. (необязательно)</span>
                    <input class="form-control" type="number" name="{{ $prefix }}_price" min="0" max="99999999.99" step="0.01" inputmode="decimal" value="{{ old($prefix.'_price', $item['price'] ?? '') }}" @disabled(!$selected)>
                </label>
                <label class="field-group">
                    <span>Комментарий (необязательно)</span>
                    <input class="form-control" type="text" name="{{ $prefix }}_comment" maxlength="250" value="{{ old($prefix.'_comment', $item['comment'] ?? '') }}" @disabled(!$selected)>
                </label>
                @error($prefix.'_title') <small role="alert">{{ $message }}</small> @enderror
                @error($prefix.'_price') <small role="alert">{{ $message }}</small> @enderror
                @error($prefix.'_comment') <small role="alert">{{ $message }}</small> @enderror
            </div>
        </div>
    @endforeach
</div>
<script>
(()=>{
    const root=document.currentScript.previousElementSibling;
    const form=root.closest('form');
    root.querySelectorAll('[data-cost-inclusion-row]').forEach(row=>{
        const value=row.querySelector('[data-cost-inclusion-value]');
        const toggle=row.querySelector('[data-cost-inclusion-check]');
        const details=row.querySelector('[data-cost-inclusion-details]');
        function refresh(){
            const selected=value.value==='1';
            toggle.checked=selected;
            details.hidden=!selected;
            details.style.display=selected?'':'none';
            details.querySelectorAll('input').forEach(field=>field.disabled=!selected);
            const title=details.querySelector('[data-cost-inclusion-title]');
            if(title)title.required=selected;
        }
        toggle.addEventListener('change',()=>{value.value=toggle.checked?'1':'0';refresh();});
        form?.addEventListener('mskba:event-pricing-restore',refresh);
        form?.addEventListener('reset',()=>queueMicrotask(refresh));
        refresh();
    });
})();
</script>
