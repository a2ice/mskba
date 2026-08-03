document.addEventListener('DOMContentLoaded', () => {
    collapseFilters({
        bodySelector: '[data-venue-filters]',
        toggleSelector: '[data-venue-filter-toggle]',
        iconSelector: '[data-venue-filter-toggle-icon]',
        toolbarSelector: '.venues-catalog-toolbar',
    });

    collapseFilters({
        bodySelector: '[data-event-filter-body]',
        toggleSelector: '[data-event-filter-toggle]',
        iconSelector: '[data-event-filter-toggle-icon]',
        toolbarSelector: '.events-catalog-filters__toolbar',
    });
});

function collapseFilters({ bodySelector, toggleSelector, iconSelector, toolbarSelector }) {
    const body = document.querySelector(bodySelector);
    const toggle = document.querySelector(toggleSelector);
    const icon = document.querySelector(iconSelector);

    if (!body || !toggle) {
        return;
    }

    body.hidden = true;
    toggle.setAttribute('aria-expanded', 'false');
    icon?.classList.remove('ti-chevron-up');
    icon?.classList.add('ti-chevron-down');
    toggle.closest(toolbarSelector)?.classList.add('is-filters-collapsed');
}
