import './default-category.js';
import './event-catalog.js';

document.addEventListener('DOMContentLoaded', () => {
    applyEventCatalogDefaults();
});

function applyEventCatalogDefaults() {
    const form = document.querySelector('[data-event-catalog-filter-form]');

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

function currentMoscowDate() {
    return new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Europe/Moscow',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(new Date());
}
