// A venue interest is a subscription, independent of favorites.
(() => {
    const dialog = document.querySelector('[data-venue-interest-confirm]');
    if (!dialog) return;
    const error = dialog.querySelector('[data-venue-interest-error]');
    const confirm = dialog.querySelector('[data-venue-interest-confirm-submit]');
    const toast = (message, failed = false) => window.dispatchEvent(
        new CustomEvent('mskba:action-toast', {detail: {message, failed}})
    );
    // Audience markup is replaced via AJAX after saves. Never hold a stale
    // reference to a button living inside that replaceable summary.
    const getButtons = () => ({
        add: document.querySelector('[data-venue-interest-add]'),
        remove: document.querySelector('[data-venue-interest-remove]'),
    });
    const showState = active => {
        const {add, remove} = getButtons();
        if (add) add.hidden = active;
        if (remove) remove.hidden = !active;
    };
    let removal = null;
    let busy = false;
    let backgroundPressed = false;

    document.addEventListener('click', event => {
        const button = event.target.closest?.('[data-venue-interest-remove]');
        if (!button || button.hidden) return;
        event.preventDefault();
        removal = {url: button.dataset.removeUrl, venueId: Number(button.dataset.venueId)};
        error.hidden = true;
        error.textContent = '';
        if (!dialog.open) dialog.showModal();
    });
    dialog.querySelectorAll('[data-venue-interest-confirm-close]').forEach(button =>
        button.addEventListener('click', () => dialog.close()));
    dialog.addEventListener('close', () => {removal = null;});
    dialog.addEventListener('pointerdown', event => {backgroundPressed = event.target === dialog;});
    dialog.addEventListener('pointerup', event => {
        if (backgroundPressed && event.target === dialog) dialog.close();
        backgroundPressed = false;
    });
    confirm.addEventListener('click', async () => {
        if (busy || !removal) return;
        const current = removal;
        busy = true;
        confirm.disabled = true;
        error.hidden = true;
        try {
            const response = await fetch(current.url, {
                method: 'DELETE', credentials: 'same-origin',
                headers: {
                    Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
            });
            const result = await response.json().catch(() => ({}));
            if (!response.ok) throw Error(result.message || 'Не удалось убрать площадку из интересов.');
            dialog.close();
            showState(false);
            toast('Площадка убрана из интересов');
            window.dispatchEvent(new CustomEvent('mskba:play-intent:removed', {
                detail: {venueId: current.venueId},
            }));
        } catch (exception) {
            error.textContent = exception.message;
            error.hidden = false;
        } finally {
            busy = false;
            confirm.disabled = false;
        }
    });
    window.addEventListener('mskba:play-intent:saved', event => {
        const {remove} = getButtons();
        if (Number(event.detail?.venueId) === Number(remove?.dataset.venueId)) showState(true);
    });
})();