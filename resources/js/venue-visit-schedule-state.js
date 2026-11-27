// Release only buttons disabled by this refresh, preserving other constraints
// and all visible saved occupancy. The normal day request still verifies data.
export function markVisitRefreshFailed(section, pending) {
    for (const scope of section.querySelectorAll('[data-venue-refresh-scope]')) {
        if (!pending.has(scope.dataset.venueRefreshScope)) continue;
        const message = scope.querySelector('[data-venue-refresh-message]');
        if (message?.dataset.refreshState === 'updating') {
            message.dataset.refreshState = 'failed';
            const observed = message.dataset.refreshObserved;
            message.title = 'Не удалось обновить расписание. Показаны последние сохранённые данные.'
                + (observed ? ' · Последнее обновление: ' + observed : '');
            message.querySelector('[data-venue-refresh-spinner]').hidden = true;
            message.querySelector('[data-venue-refresh-error]').hidden = false;
            message.querySelector('[data-venue-refresh-label]').textContent = 'Не удалось обновить';
        }
        for (const days of scope.querySelectorAll('[data-venue-refresh-days]')) {
            days.setAttribute('aria-busy', 'false');
        }
        for (const button of scope.querySelectorAll('[data-venue-refresh-disabled]')) {
            button.disabled = false;
            button.removeAttribute('data-venue-refresh-disabled');
        }
    }
}
