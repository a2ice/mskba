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
        // Programmatic assignment doesn't emit input/change events. Notify the
        // shared validation handler so a previous "required" error is cleared.
        // Use change (not input): an input event would mark this as a manual name
        // and prevent future address selections from updating the suggestion.
        name.dispatchEvent(new Event('change', { bubbles: true }));
    });
});
