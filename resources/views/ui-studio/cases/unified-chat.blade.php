{{-- Only fixture glue; real product composer is included, not copied. --}}
<main style="max-width:680px;margin-inline:auto;padding:24px 16px">
    <section class="panel" style="padding:16px">
        <h2 style="margin-bottom:16px">Чат мероприятия</h2>
        <div data-studio-messages aria-live="polite" style="display:grid;gap:8px;margin-bottom:16px">
            @if($state === 'normal')<p>Договорились, встречаемся в 19:00.</p>@endif
        </div>
        @include('components.chat-composer',['chatComposerId'=>'ui-studio-message','chatActions'=>[],'primaryButton'=>'button primary'])
    </section>
</main>
<script>
(() => {
    const form = document.querySelector('[data-chat-composer]');
    const field = form.querySelector('textarea');
    const submit = form.querySelector('[type=submit]');
    const counter = form.querySelector('[data-venue-play-chars]');
    const messages = document.querySelector('[data-studio-messages]');
    form.hidden = false;
    const disabled = @json($state === 'disabled');
    form.dataset.chatCanPost = String(!disabled);
    field.disabled = disabled;
    const sync = () => { counter.textContent = 200 - field.value.length; submit.disabled = disabled || !field.value.trim(); };
    field.addEventListener('input', sync);
    form.addEventListener('submit', event => {
        event.preventDefault();
        if (submit.disabled || !field.value.trim()) return;
        const message = document.createElement('p');
        message.textContent = field.value.trim();
        messages.append(message);
        field.value = '';
        sync();
    });
    sync();
})();
</script>
