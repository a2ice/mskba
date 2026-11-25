document.addEventListener('change', event => {
    if (!event.target?.matches('[data-venue-type-filter] input[name="type[]"]')) return;
    const root = event.target.closest('[data-venue-type-filter]');
    const labels = Array.from(root.querySelectorAll('input:checked')).map(input => input.dataset.venueTypeLabel);
    root.querySelector('[data-venue-type-summary]').textContent = labels.join(', ') || 'Все типы';
});
