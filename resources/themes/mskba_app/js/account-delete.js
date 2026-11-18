// A destructive action must never be reachable without explicit modal confirmation.
const dialog = document.querySelector('[data-account-delete-dialog]');
const form = document.querySelector('[data-account-delete-form]');
const opener = document.querySelector('[data-account-delete-open]');

if (dialog && form && opener && typeof dialog.showModal === 'function') {
    const confirm = dialog.querySelector('[data-account-delete-confirm]');
    let previousOverflow = '';

    opener.addEventListener('click', () => {
        previousOverflow = document.body.style.overflow;
        dialog.showModal();
        document.body.style.overflow = 'hidden';
        dialog.querySelector('[data-account-delete-cancel]')?.focus({ preventScroll: true });
    });

    dialog.querySelectorAll('[data-account-delete-cancel]').forEach(button =>
        button.addEventListener('click', () => dialog.close()));

    dialog.addEventListener('click', event => {
        if (event.target === dialog) dialog.close();
    });
    dialog.addEventListener('close', () => {
        document.body.style.overflow = previousOverflow;
        if (opener.isConnected) opener.focus({ preventScroll: true });
    });

    confirm.addEventListener('click', () => {
        if (confirm.disabled) return;
        confirm.disabled = true;
        form.submit(); // Native CSRF-protected DELETE; no pre-confirm form submit.
    });
}
