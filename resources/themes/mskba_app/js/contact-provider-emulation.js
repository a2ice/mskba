// Explicit non-production identity-provider emulator shared by both contact dialogs.
// Outcomes come from the server; the browser cannot decide that a contact is verified.
export function createContactProviderEmulation({ root, dialog, post, onSuccess, onError, onBusy }) {
    const step = dialog.querySelector('[data-provider-emulation-step]');
    const outcome = step?.querySelector('[data-provider-emulation-outcome]');
    const run = step?.querySelector('[data-provider-emulation-run]');
    let provider = null;
    let busy = false;
    if (!step || !run || !root.dataset.emulateUrl) {
        return { available: false, choose() {}, reset() {} };
    }

    function choose(value) {
        provider = value;
        outcome.value = 'success';
        run.disabled = false;
        run.textContent = 'Проверить сценарий ' + (value === 'telegram' ? 'Telegram' : 'VK');
    }
    function reset() {
        busy = false;
        provider = null;
        run.disabled = false;
        run.textContent = 'Проверить сценарий';
    }

    run.addEventListener('click', async () => {
        if (busy || !provider || !dialog.open) return;
        busy = true;
        onBusy(true);
        onError('');
        run.disabled = true;
        run.textContent = 'Проверяем…';
        try {
            const result = await post(root.dataset.emulateUrl, { provider, outcome: outcome.value });
            if (dialog.open) await onSuccess(result);
        } catch (error) {
            if (dialog.open) onError(error.message || 'Не удалось выполнить тестовый сценарий.');
        } finally {
            busy = false;
            onBusy(false);
            run.disabled = false;
            run.textContent = 'Проверить сценарий ещё раз';
        }
    });

    return { available: true, choose, reset };
}
