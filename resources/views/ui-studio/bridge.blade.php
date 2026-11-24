<!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>Подключение к UI Studio · MSKBA</title>
</head><body style="font:15px system-ui,sans-serif;padding:30px;background:#f3f4f6;color:#222">
<p id="status" role="status">Проверяем доступ из Filament…</p>
<script>
(() => {
    const trusted = @json(config('ui-studio.parent_origins', []));
    const status = document.getElementById('status');
    if (window.parent === window) { status.textContent='Откройте Studio из Filament MSKBA.'; return; }
    window.addEventListener('message', async event => {
        if (!trusted.includes(event.origin) || event.source !== window.parent || event.data?.type !== 'mskba-ui-studio:ticket') return;
        try {
            const response = await fetch(@json(route('ui-studio.exchange', absolute:false)), {
                method: 'POST', credentials: 'same-origin',
                headers: {'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':@json(csrf_token())},
                body:JSON.stringify({ticket:event.data.ticket})
            });
            if (!response.ok) throw new Error('HTTP '+response.status);
            window.parent.postMessage({type:'mskba-ui-studio:authorized'},event.origin);
            window.location.replace(@json(route('ui-studio.show', absolute:false)));
        } catch (error) {
            status.textContent='Авторизация не завершена: '+error.message;
            window.parent.postMessage({type:'mskba-ui-studio:error',message:error.message},event.origin);
        }
    });
    trusted.forEach(origin => window.parent.postMessage({type:'mskba-ui-studio:ready'},origin));
})();
</script></body></html>
