// A direct venue interest is a subscription, not a favorite or a booking.
(() => {
    const confirmDialog = document.querySelector('[data-venue-interest-confirm]');
    const addButton = document.querySelector('[data-venue-interest-add]');
    const removeButton = document.querySelector('[data-venue-interest-remove]');
    if (!confirmDialog || !addButton || !removeButton) return;

    const error = confirmDialog.querySelector('[data-venue-interest-error]');
    const confirm = confirmDialog.querySelector('[data-venue-interest-confirm-submit]');
    const toast = (message, failed = false) => window.dispatchEvent(
        new CustomEvent('mskba:action-toast', {detail: {message, failed}})
    );
    const showState = active => {
        addButton.hidden = active;
        removeButton.hidden = !active;
    };
    let busy = false;
    let startedOutside = false;

    removeButton.addEventListener('click', () => {
        error.hidden = true;
        error.textContent = '';
        if (!confirmDialog.open) confirmDialog.showModal();
    });
    confirmDialog.querySelectorAll('[data-venue-interest-confirm-close]').forEach(button =>
        button.addEventListener('click', () => confirmDialog.close()));
    confirmDialog.addEventListener('pointerdown', e => { startedOutside = e.target === confirmDialog; });
    confirmDialog.addEventListener('pointerup', e => {
        if (startedOutside && e.target === confirmDialog) confirmDialog.close();
        startedOutside = false;
    });
    confirm.addEventListener('click', async () => {
        if (busy) return;
        busy = true;
        confirm.disabled = true;
        error.hidden = true;
        try {
            const response = await fetch(removeButton.dataset.removeUrl, {
                method: 'DELETE', credentials: 'same-origin',
                headers: {
                    Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
            });
            const result = await response.json().catch(() => ({}));
            if (!response.ok) throw Error(result.message || 'Не удалось убрать площадку из интересов.');
            confirmDialog.close();
            showState(false);
            toast('Площадка убрана из интересов');
            window.dispatchEvent(new CustomEvent('mskba:play-intent:removed', {
                detail: {venueId: Number(removeButton.dataset.venueId)},
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
        if (Number(event.detail?.venueId) === Number(removeButton.dataset.venueId)) showState(true);
    });
})();