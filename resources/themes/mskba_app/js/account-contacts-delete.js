const dialog = document.querySelector('[data-contact-delete-dialog]');

if (dialog) {
    const targetLabel = dialog.querySelector('[data-contact-delete-target]');
    const confirmButton = dialog.querySelector('[data-contact-delete-confirm]');
    const cancelButtons = dialog.querySelectorAll('[data-contact-delete-cancel]');

    let pendingForm = null;
    let opener = null;
    let previousOverflow = '';

    // Delegation also covers contacts added via AJAX without rebinding events.
    document.addEventListener('submit', event => {
        const form = event.target;
        if (!form.matches?.('[data-contact-delete-form]')) return;

        event.preventDefault();
        if (dialog.open) return;

        pendingForm = form;
        opener = event.submitter || form.querySelector('button[type="submit"]');
        targetLabel.textContent = form.dataset.contactLabel || '';
        confirmButton.disabled = false;
        previousOverflow = document.body.style.overflow;
        dialog.showModal();
        document.body.style.overflow = 'hidden';
        dialog.querySelector('.app-account-contacts__delete-footer [data-contact-delete-cancel]')?.focus({ preventScroll: true });
    });

    cancelButtons.forEach(button => {
        button.addEventListener('click', () => dialog.close());
    });
    dialog.addEventListener('click', event => {
        if (event.target === dialog) dialog.close();
    });
    dialog.addEventListener('close', () => {
        document.body.style.overflow = previousOverflow;
        pendingForm = null;
        opener?.isConnected && opener.focus({ preventScroll: true });
        opener = null;
    });
    confirmButton.addEventListener('click', () => {
        if (!pendingForm?.isConnected || confirmButton.disabled) {
            dialog.close();
            return;
        }
        confirmButton.disabled = true;
        // Submit the original CSRF-protected DELETE form only after confirmation.
        // Native form.submit() intentionally bypasses the delegated submit handler.
        pendingForm.submit();
    });
}
