@php
    $lifecycleTitle = $restoring ? 'Восстановить мероприятие' : 'Удалить мероприятие';
    $linkedBooking = $event->sourceBooking ?? $event->booking;
    $hasBooking = !$restoring && $linkedBooking && !$linkedBooking->status->isTerminal();
    $bookingTimezone = $linkedBooking?->venue?->schedule?->timezone ?: config('app.timezone', 'Europe/Moscow');
@endphp
<dialog class="mskba-modal event-lifecycle-dialog" id="event-lifecycle-dialog" aria-labelledby="event-lifecycle-title">
    <header class="mskba-modal__header">
        <h2 id="event-lifecycle-title">{{ $lifecycleTitle }}</h2>
        <button type="button" class="icon-button" data-event-lifecycle-close aria-label="Закрыть"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6l-12 12M6 6l12 12"/></svg></button>
    </header>
    <form method="POST" action="{{ route($restoring ? 'events.lifecycle.restore' : 'events.lifecycle.delete', $event->routeIdentifier()) }}">
        @csrf
        <div class="mskba-modal__body mskba-scroll">
            @if($hasBooking)
                <p class="event-lifecycle-booking-warning">
                    К данному мероприятию привязана бронь:
                    <strong>{{ $linkedBooking->venue?->name ?? $event->venue?->name }}@if($linkedBooking->court) · {{ $linkedBooking->court->name }}@endif,
                    {{ $linkedBooking->starts_at?->setTimezone($bookingTimezone)->format('d.m.Y H:i') }}–{{ $linkedBooking->ends_at?->setTimezone($bookingTimezone)->format('H:i') }}</strong>.
                    Бронирование не будет отменено при удалении мероприятия.
                </p>
            @endif
            <label class="field-group" for="event-lifecycle-reason">Причина
                <textarea id="event-lifecycle-reason" name="reason" rows="4" minlength="10" maxlength="2000" required placeholder="Не менее 10 символов">{{ old('reason') }}</textarea>
            </label>
            @error('reason')<p role="alert">{{ $message }}</p>@enderror
        </div>
        <footer class="mskba-modal__footer">
            <button type="submit" class="button {{ $restoring ? 'primary' : 'secondary event-lifecycle-danger' }}" data-event-lifecycle-confirm>{{ $restoring ? 'Подтвердить' : ($hasBooking ? 'Всё равно удалить' : 'Да, удалить') }}</button>
        </footer>
    </form>
</dialog>
@push('styles')
<style>
.event-lifecycle-danger{color:var(--danger)}
.event-lifecycle-booking-warning{margin:0 0 16px;overflow-wrap:anywhere}
.event-lifecycle-dialog>form{display:flex;flex-direction:column;min-height:0}
.event-lifecycle-dialog textarea{width:100%;resize:vertical}
.event-lifecycle-dialog .mskba-modal__footer{display:flex;justify-content:flex-end}
html:has(.event-lifecycle-dialog[open]){overflow:hidden}
</style>
@endpush
@push('scripts')
<script type="module">
const dialog = document.getElementById('event-lifecycle-dialog');
if (dialog) {
    const reason = dialog.querySelector('[name="reason"]');
    const confirm = dialog.querySelector('[data-event-lifecycle-confirm]');
    const sync = () => { confirm.disabled = Array.from(reason.value.trim()).length < 10; };
    reason.addEventListener('input', sync);
    sync();
    document.querySelectorAll('[data-event-lifecycle-open]').forEach(button => button.addEventListener('click', () => dialog.showModal()));
    dialog.querySelector('[data-event-lifecycle-close]').addEventListener('click', () => dialog.close());
    let outside = false;
    const backdrop = e => { const r = dialog.getBoundingClientRect(); return e.target === dialog && (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom); };
    dialog.addEventListener('pointerdown',e=>outside=backdrop(e));
    dialog.addEventListener('pointerup',e=>{if(outside&&backdrop(e))dialog.close();outside=false;});
    dialog.addEventListener('pointercancel',()=>outside=false);
    @if($errors->has('reason'))
    dialog.showModal();
    @endif
}
</script>
@endpush
