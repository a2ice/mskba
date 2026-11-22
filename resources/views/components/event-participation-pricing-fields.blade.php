@php
    $pricingEvent = $pricingEvent ?? null;
    $pricingPaid = (bool) old('is_paid', $pricingEvent?->is_paid ?? false);
    $pricingAuto = (bool) old('participant_cost_auto', $pricingEvent?->participant_cost_auto ?? true);
    $pricingTotal = old('total_cost', $pricingEvent?->total_cost_minor === null ? '' : number_format($pricingEvent->total_cost_minor / 100, 2, '.', ''));
    $pricingShare = old('participant_cost', $pricingEvent?->participant_cost_minor === null ? '' : number_format($pricingEvent->participant_cost_minor / 100, 2, '.', ''));
    $pricingDefault = \App\Modules\Event\Application\Services\EventParticipationPricing::defaultComment(auth()->user());
@endphp
<div data-event-pricing-fields class="field-group" style="display:grid;gap:10px">
    <input type="hidden" name="is_paid" value="{{ $pricingPaid ? '1' : '0' }}">
    <label style="display:flex;align-items:center;gap:8px">
        <input type="checkbox" data-event-paid @checked($pricingPaid)> Платное мероприятие
    </label>
    <div data-event-paid-fields @if(!$pricingPaid) hidden @endif>
        <label class="field-group" style="display:grid;gap:5px;margin-bottom:10px">
            <span>Общая стоимость мероприятия, руб.</span>
            <input class="form-control" type="number" name="total_cost" min="0.01" max="99999999.99" step="0.01" inputmode="decimal" value="{{ $pricingTotal }}" @disabled(!$pricingPaid) @required($pricingPaid)>
        </label>
        <label class="field-group" style="display:grid;gap:5px;margin-bottom:10px">
            <span>Стоимость участия, руб.</span>
            <input class="form-control" type="number" name="participant_cost" min="0.01" max="99999999.99" step="0.01" inputmode="decimal" value="{{ $pricingShare }}" @disabled(!$pricingPaid) @required($pricingPaid)>
        </label>
        <input type="hidden" name="participant_cost_auto" value="{{ $pricingAuto ? '1' : '0' }}">
        <button type="button" class="button secondary" data-event-price-auto style="margin-bottom:10px" @if($pricingAuto) hidden @endif>Рассчитать автоматически</button>
        <small data-event-price-hint class="muted"></small>
        <label class="field-group" style="display:grid;gap:5px;margin:10px 0">
            <span>Комментарий к стоимости</span>
            <textarea class="form-control" name="cost_comment" rows="4" maxlength="2000" @disabled(!$pricingPaid)>{{ old('cost_comment', $pricingEvent?->cost_comment ?? $pricingDefault) }}</textarea>
        </label>
        <input type="hidden" name="save_cost_comment_default" value="0">
        <label style="display:flex;align-items:flex-start;gap:8px">
            <input type="checkbox" data-event-cost-save-default @disabled(!$pricingPaid)> Использовать этот комментарий по умолчанию
        </label>
    </div>
    @error('total_cost') <small role="alert">{{ $message }}</small> @enderror
    @error('participant_cost') <small role="alert">{{ $message }}</small> @enderror
    @error('cost_comment') <small role="alert">{{ $message }}</small> @enderror
</div>
<script>
(()=>{
    const root=document.currentScript.previousElementSibling;
    const form=root.closest('form');
    const paid=root.querySelector('[name=is_paid]'),toggle=root.querySelector('[data-event-paid]');
    const area=root.querySelector('[data-event-paid-fields]');
    const total=root.querySelector('[name=total_cost]'),share=root.querySelector('[name=participant_cost]');
    const mode=root.querySelector('[name=participant_cost_auto]'),reset=root.querySelector('[data-event-price-auto]');
    const comment=root.querySelector('[name=cost_comment]'),save=root.querySelector('[name=save_cost_comment_default]');
    const saveToggle=root.querySelector('[data-event-cost-save-default]');
    const capacity=form.querySelector('[name=max_participants]');
    const hint=root.querySelector('[data-event-price-hint]');
    function refresh(){
        const enabled=paid.value==='1';
        toggle.checked=enabled;
        area.hidden=!enabled;
        area.style.display=enabled?'':'none';
        [total,share,comment,saveToggle].forEach(field=>field.disabled=!enabled);
        total.required=share.required=enabled;
        saveToggle.checked=save.value==='1';
        const count=Math.max(1,Number(capacity?.value)||10);
        const auto=mode.value==='1';
        reset.hidden=auto;
        reset.style.display=auto?'none':'';
        hint.textContent=auto?'Авторасчёт: общая стоимость / '+count+' участн.':'Стоимость участия задана вручную.';
        if(auto&&enabled){
            const value=Number(total.value);
            share.value=value>0?(Math.max(1,Math.round(Math.round(value*100)/count))/100).toFixed(2):'';
        }
    }
    toggle.addEventListener('change',()=>{paid.value=toggle.checked?'1':'0';refresh();});
    total.addEventListener('input',refresh);
    capacity?.addEventListener('input',refresh);
    share.addEventListener('input',()=>{mode.value='0';refresh();});
    reset.addEventListener('click',()=>{mode.value='1';refresh();});
    saveToggle.addEventListener('change',()=>{save.value=saveToggle.checked?'1':'0';});
    form.addEventListener('mskba:event-pricing-restore',refresh);
    refresh();
})();
</script>
