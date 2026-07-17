document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-admin-user-bulk-form]').forEach((form) => {
        const rows = Array.from(form.querySelectorAll('[data-admin-user-select]:not(:disabled)'));
        const selectAll = form.querySelector('[data-admin-user-select-all]');
        const submit = form.querySelector('[data-admin-user-bulk-submit]');

        if (!rows.length || !selectAll || !submit) {
            return;
        }

        const sync = () => {
            const selectedCount = rows.filter((checkbox) => checkbox.checked).length;
            submit.disabled = selectedCount === 0;
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
            if (!window.confirm(form.dataset.confirmMessage)) {
                event.preventDefault();
            }
        });

        sync();
    });
});
