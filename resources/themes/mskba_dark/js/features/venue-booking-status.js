document.addEventListener('DOMContentLoaded', async () => {
    const venuePage = document.querySelector('.venue-show');
    const action = document.querySelector('.venue-booking-action');
    if (!venuePage || !action) return;

    const match = window.location.pathname.match(/^\/venues\/([^/]+)\/?$/);
    const routeIdentifier = match?.[1] || '';
    if (!routeIdentifier) return;

    try {
        const response = await fetch(`/venues/${encodeURIComponent(routeIdentifier)}/activities`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok || payload.operational_status !== 'temporarily_closed') return;

        const disabled = document.createElement('button');
        disabled.type = 'button';
        disabled.className = action.className;
        disabled.disabled = true;
        disabled.setAttribute('aria-label', 'Площадка временно закрыта — бронирование недоступно');
        disabled.title = 'Площадка временно закрыта';
        disabled.innerHTML = `
            <i class="ti ti-lock venue-booking-action__icon" aria-hidden="true"></i>
            <span class="venue-booking-action__label">Недоступно</span>
        `;
        action.replaceWith(disabled);
    } catch (_) {
        // Keep the normal booking flow if the auxiliary status request is unavailable.
    }
});
