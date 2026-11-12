// Shared confirmation review for account, player and future participation roles.
// The server still validates current values; the dialog is only an explicit UX acknowledgment.
document.querySelectorAll('[data-confirm-review-dialog]').forEach(dialog => {
    const form = document.getElementById(dialog.dataset.confirmReviewTarget);
    if (!form) return;
    let accepted = false;
    let previousFocus = null;

    const refreshSavedValues = () => {
        dialog.querySelectorAll('[data-confirm-review-live]').forEach(target => {
            const field = target.dataset.confirmReviewLive;
            const row = form.querySelector('[data-confirmation-field][data-field-name="' + field + '"]');
            if (!row) return;
            let value = row.dataset.savedValue || '';
            if (field === 'gender') {
                value = ({male: 'Мужской', female: 'Женский'})[value] || value;
            } else if (field === 'birth_date' && /^[0-9]{4}-[0-9]{2}-[0-9]{2}$/.test(value)) {
                const [year, month, day] = value.split('-');
                value = [day, month, year].join('.');
            }
            target.textContent = value || 'Не указано';
        });
    };

    form.addEventListener('submit', event => {
        if (accepted) {
            accepted = false;
            return;
        }
        if (event.defaultPrevented || !form.checkValidity()) return;
        event.preventDefault();
        refreshSavedValues();
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
