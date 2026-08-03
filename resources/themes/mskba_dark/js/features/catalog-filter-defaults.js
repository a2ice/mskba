document.addEventListener('DOMContentLoaded', () => {
    const redirected = applyEventCatalogDefaults();

    if (redirected) {
        return;
    }

    prepareEventCatalogFilters();

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

function applyEventCatalogDefaults() {
    const form = document.querySelector('[data-event-filter-body]');

    if (!form) {
        return false;
    }

    const url = new URL(window.location.href);
    let changed = false;

    if (!url.searchParams.has('type')) {
        url.searchParams.set('type', 'games');
        changed = true;
    }

    if (!url.searchParams.has('date_from') && url.searchParams.get('period') !== 'past') {
        url.searchParams.set('date_from', currentMoscowDate());
        changed = true;
    }

    if (changed) {
        window.location.replace(url.toString());
        return true;
    }

    return false;
}

function prepareEventCatalogFilters() {
    const form = document.querySelector('[data-event-filter-body]');

    if (!form) {
        return;
    }

    form.querySelectorAll('[onchange]').forEach((control) => {
        control.onchange = null;
        control.removeAttribute('onchange');
    });

    const quickFilters = form.querySelector('.events-catalog-filters__quick');
    const pastControl = quickFilters?.querySelector(':scope > .events-filter-chip:first-child');

    if (pastControl) {
        const wrapper = document.createElement('label');
        wrapper.className = 'events-filter-chip events-filter-chip--toggle';

        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.name = 'period';
        checkbox.value = 'past';
        checkbox.checked = new URL(window.location.href).searchParams.get('period') === 'past';

        const label = document.createElement('span');
        label.innerHTML = '<i class="ti ti-history" aria-hidden="true"></i>Показывать прошедшие';

        wrapper.append(checkbox, label);
        pastControl.replaceWith(wrapper);
    }
}

function currentMoscowDate() {
    return new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Europe/Moscow',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(new Date());
}

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
