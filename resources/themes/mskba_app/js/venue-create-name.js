/* Sync venue title with selected address without overriding manual edits. */
document.querySelectorAll('[data-mskba-venue-create]').forEach(form => {
    const name = form.querySelector('[name="name"]');
    if (!name) return;
    let manuallyNamed = name.value.trim() !== '';
    name.addEventListener('input', () => {
        manuallyNamed = name.value.trim() !== '';
    });
    form.addEventListener('address-suggest:selected', event => {
        const street = String(event.detail?.street || '').trim();
        const building = String(event.detail?.building || '').trim();
        if (!street || !building || manuallyNamed) return;
        const suggested = (street + ', ' + building).slice(0, name.maxLength > 0 ? name.maxLength : 255);
        name.value = suggested;
    });
});
