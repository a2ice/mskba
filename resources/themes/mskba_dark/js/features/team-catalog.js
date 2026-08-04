document.addEventListener('DOMContentLoaded', () => {
    const catalog = document.querySelector('[data-team-catalog]');
    if (!catalog) return;

    const filters = catalog.querySelector('[data-team-filters]');
    const toggle = catalog.querySelector('[data-team-filter-toggle]');
    const icon = catalog.querySelector('[data-team-filter-toggle-icon]');

    toggle?.addEventListener('click', () => {
        const open = filters.hidden;
        filters.hidden = !open;
        toggle.setAttribute('aria-expanded', String(open));
        toggle.closest('.teams-catalog-toolbar')?.classList.toggle('is-filters-collapsed', !open);
        icon?.classList.toggle('ti-chevron-up', open);
        icon?.classList.toggle('ti-chevron-down', !open);
    });
});
