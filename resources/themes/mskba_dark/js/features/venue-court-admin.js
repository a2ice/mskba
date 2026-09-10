document.querySelectorAll('[data-court-form]').forEach((form) => {
    const hoops = form.querySelector('[data-court-hoops]');
    const halfOption = form.querySelector('[data-court-half-option]');
    const halfCheckbox = halfOption?.querySelector('input[type="checkbox"][name="allows_halves"]');
    const hint = form.querySelector('[data-court-halves-hint]');

    if (!hoops || !halfOption || !halfCheckbox) return;

    const sync = () => {
        const canSplit = Number(hoops.value) >= 2;
        halfOption.hidden = !canSplit;
        if (hint) hint.hidden = canSplit;
        if (!canSplit) halfCheckbox.checked = false;
    };

    hoops.addEventListener('change', sync);
    sync();
});
