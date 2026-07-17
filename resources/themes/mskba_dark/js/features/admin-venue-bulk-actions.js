document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-admin-venue-bulk-form]').forEach((form) => {
        const rows = Array.from(form.querySelectorAll('[data-admin-venue-select]'));
        const selectAll = form.querySelector('[data-admin-venue-select-all]');
        const submits = Array.from(form.querySelectorAll('[data-admin-venue-bulk-submit]'));
        const messageInput = form.querySelector('[data-admin-venue-bulk-message]');

        if (!rows.length || !selectAll || !submits.length) {
            return;
        }

        const sync = () => {
            const selected = rows.filter((checkbox) => checkbox.checked);
            const selectedCount = selected.length;

            submits.forEach((submit) => {
                const action = submit.dataset.adminVenueBulkAction;

                submit.disabled = selectedCount === 0
                    || (action === 'block' && selected.some((checkbox) => checkbox.dataset.venueStatus === 'blocked'))
                    || (action === 'unblock' && selected.some((checkbox) => checkbox.dataset.venueStatus !== 'blocked'));
            });
            selectAll.checked = selectedCount === rows.length;
            selectAll.indeterminate = selectedCount > 0 && selectedCount < rows.length;
        };

        rows.forEach((checkbox) => checkbox.addEventListener('change', sync));
        selectAll.addEventListener('change', () => {
            rows.forEach((checkbox) => {
                checkbox.checked = selectAll.checked;
            });
            sync();
        });

        form.addEventListener('submit', (event) => {
            const action = event.submitter?.dataset.adminVenueBulkAction;

            if (action === 'delete' && !window.confirm(form.dataset.confirmMessage)) {
                event.preventDefault();
                return;
            }

            if (action === 'block') {
                const reason = window.prompt('Укажите причину блокировки:');

                if (!reason?.trim()) {
                    event.preventDefault();
                    return;
                }

                messageInput.value = reason.trim();
            }
        });

        sync();
    });
});
