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
    const dateFrom = form.querySelector('[name="date_from"]');

    if (!pastControl) {
        return;
    }

    const wrapper = document.createElement('div');
    wrapper.className = 'events-filter-chip events-filter-chip--brand-toggle';
    wrapper.style.marginBottom = '12px';

    const label = document.createElement('label');
    label.className = 'form-toggle';
    label.htmlFor = 'events-show-past';

    const checkbox = document.createElement('input');
    checkbox.id = 'events-show-past';
    checkbox.className = 'form-toggle__input';
    checkbox.type = 'checkbox';
    checkbox.name = 'period';
    checkbox.value = 'past';
    checkbox.checked = new URL(window.location.href).searchParams.get('period') === 'past';

    const control = document.createElement('span');
    control.className = 'form-toggle__control';
    control.setAttribute('aria-hidden', 'true');

    const title = document.createElement('strong');
    title.className = 'form-toggle__title';
    title.textContent = 'Показывать прошедшие';

    label.append(checkbox, control, title);
    wrapper.append(label);
    pastControl.replaceWith(wrapper);

    checkbox.addEventListener('change', () => {
        if (checkbox.checked && dateFrom) {
            dateFrom.value = '';
        }
    });

    const resetPastFilter = () => {
        if (dateFrom?.value) {
            checkbox.checked = false;
        }
    };

    dateFrom?.addEventListener('input', resetPastFilter);
    dateFrom?.addEventListener('change', resetPastFilter);

    if (checkbox.checked && dateFrom?.value) {
        dateFrom.value = '';
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
