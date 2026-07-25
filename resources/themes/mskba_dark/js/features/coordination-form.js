document.addEventListener('click', (event) => {
    const addButton = event.target.closest('[data-coordination-option-add]');

    if (addButton) {
        const container = addButton.closest('form')?.querySelector('[data-coordination-options]');

        if (!container || container.children.length >= 20) {
            return;
        }

        const row = document.createElement('div');
        row.className = 'coordination-option-row';
        row.innerHTML = `
            <input class="form-control" name="options[]" maxlength="255" required>
            <button class="btn btn--secondary btn--sm" type="button" data-coordination-option-remove aria-label="Удалить вариант">×</button>
        `;
        container.append(row);
        row.querySelector('input')?.focus();
        return;
    }

    const removeButton = event.target.closest('[data-coordination-option-remove]');

    if (!removeButton) {
        return;
    }

    const container = removeButton.closest('[data-coordination-options]');

    if (container && container.children.length > 2) {
        removeButton.closest('.coordination-option-row')?.remove();
    }
});
