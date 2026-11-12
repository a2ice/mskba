// Shared confirmation review for account, player and future participation roles.
// The server still validates current values; the dialog is only an explicit UX acknowledgment.
document.querySelectorAll('[data-confirm-review-dialog]').forEach(dialog => {
    const form = document.getElementById(dialog.dataset.confirmReviewTarget);
    if (!form) return;
    let accepted = false;
    let previousFocus = null;

    form.addEventListener('submit', event => {
        if (accepted) {
            accepted = false;
            return;
        }
        if (event.defaultPrevented || !form.checkValidity()) return;
        event.preventDefault();
        previousFocus = document.activeElement;
        if (!dialog.open) {
            dialog.showModal();
            dialog.querySelector('h2[tabindex="-1"]')?.focus({ preventScroll: true });
        }
    });

    dialog.querySelectorAll('[data-confirm-review-close]').forEach(button => {
        button.addEventListener('click', () => dialog.close());
    });
    dialog.querySelector('[data-confirm-review-accept]')?.addEventListener('click', () => {
        accepted = true;
        dialog.close();
        form.requestSubmit();
    });
    dialog.addEventListener('close', () => {
        if (previousFocus?.isConnected) previousFocus.focus({ preventScroll: true });
    });
});
