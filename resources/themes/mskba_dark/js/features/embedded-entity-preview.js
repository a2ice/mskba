const modal = document.querySelector('[data-modal="embedded-entity-preview"]');
let requestController = null;

document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-entity-preview-trigger]');

    if (!trigger || !modal) {
        return;
    }

    loadVenue(trigger.dataset.entityPreviewUrl || '');
});

async function loadVenue(url) {
    const message = modal.querySelector('[data-entity-preview-message]');
    const content = modal.querySelector('[data-entity-preview-content]');

    requestController?.abort();
    requestController = new AbortController();
    message.textContent = 'Загружаем информацию…';
    message.hidden = false;
    content.hidden = true;

    try {
        const response = await fetch(url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: requestController.signal,
        });
        const payload = await response.json().catch(() => ({}));

        if (!response.ok || !payload.venue) {
            throw new Error(payload.message || 'Не удалось загрузить информацию о площадке.');
        }

        renderVenue(payload.venue);
        message.hidden = true;
        content.hidden = false;
    } catch (error) {
        if (error.name !== 'AbortError') {
            message.textContent = error.message || 'Не удалось загрузить информацию о площадке.';
        }
    }
}

function renderVenue(venue) {
    const metro = Array.isArray(venue.metro_stations)
        ? venue.metro_stations.map((station) => station.name).filter(Boolean).join(', ')
        : '';
    const setText = (selector, value) => {
        const element = modal.querySelector(selector);
        if (element) element.textContent = value;
    };
    const setOptionalText = (selector, value) => {
        const element = modal.querySelector(selector);
        if (!element) return;
        element.textContent = value;
        element.hidden = !value;
    };

    setText('[data-entity-preview-title]', venue.name || 'Площадка');
    setText('[data-entity-preview-type]', venue.type || '');
    setText('[data-entity-preview-state]', venue.is_open ? 'Открыта' : 'Закрыта');
    setText('[data-entity-preview-address]', venue.address || 'Адрес не указан');
    setText('[data-entity-preview-hours]', venue.today_hours ? `Часы работы: ${venue.today_hours}` : '');
    setOptionalText('[data-entity-preview-metro]', metro ? `Метро: ${metro}` : '');
    setOptionalText('[data-entity-preview-description]', venue.description || '');

    modal.querySelector('[data-entity-preview-state]')?.classList.toggle('is-closed', !venue.is_open);

    const image = modal.querySelector('[data-entity-preview-image]');
    const imageWrap = modal.querySelector('[data-entity-preview-image-wrap]');
    imageWrap.hidden = !venue.image_url;
    if (venue.image_url) {
        image.src = venue.image_url;
        image.alt = venue.name || 'Площадка';
    } else {
        image.removeAttribute('src');
    }

    modal.querySelector('[data-entity-preview-page]').href = venue.url || '#';
}
