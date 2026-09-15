import { loadYandexMaps } from '../core/yandex-maps.js';

const modal = document.querySelector('[data-modal="embedded-entity-preview"]');
let requestController = null;
let previewMap = null;

document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-entity-preview-trigger]');

    if (!trigger || !modal) {
        return;
    }

    loadVenue(trigger.dataset.entityPreviewUrl || '', trigger);
});

async function loadVenue(url, trigger) {
    const message = modal.querySelector('[data-entity-preview-message]');
    const content = modal.querySelector('[data-entity-preview-content]');
    const userContent = modal.querySelector('[data-user-preview-content]');

    requestController?.abort();
    requestController = new AbortController();
    const controller = requestController;
    modal.querySelector('[data-entity-preview-title]').textContent = trigger.dataset.entityType === 'user' ? 'Профиль' : 'Площадка';
    message.textContent = 'Загружаем информацию…';
    message.hidden = false;
    content.hidden = true;
    userContent.hidden = true;
    resetMap();

    try {
        const response = await fetch(url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: controller.signal,
        });
        const payload = await response.json().catch(() => ({}));
        if (controller !== requestController || controller.signal.aborted) return;

        if (!response.ok || (!payload.venue && !payload.user)) {
            throw new Error(payload.message || 'Информация недоступна.');
        }

        if (payload.user) {
            renderUser(payload.user);
            message.hidden = true;
            userContent.hidden = false;
            return;
        }

        renderVenue(payload.venue);
        message.hidden = true;
        content.hidden = false;
        renderVenueMap(payload.venue, trigger);
    } catch (error) {
        if (error.name !== 'AbortError' && controller === requestController) {
            message.textContent = error.message || 'Не удалось загрузить информацию о площадке.';
        }
    }
}

function renderUser(user) {
    modal.querySelector('[data-entity-preview-title]').textContent = user.name || 'Пользователь';
    modal.querySelector('[data-user-preview-role]').textContent = user.public_coach ? 'Тренер открытой секции' : (user.role_label || 'Пользователь');
    const avatar = modal.querySelector('[data-user-preview-avatar]');
    avatar.hidden = !user.avatar_url;
    avatar.alt = user.name || '';
    if (user.avatar_url) avatar.src = user.avatar_url;
    else avatar.removeAttribute('src');
    modal.querySelector('[data-user-preview-page]').href = user.url;
    const sections = modal.querySelector('[data-user-preview-sections]');
    sections.replaceChildren();
    for (const section of user.sections || []) {
        const item = document.createElement('li');
        const link = document.createElement('a');
        link.href = section.url;
        link.textContent = section.name;
        item.append(link);
        sections.append(item);
    }
    sections.hidden = sections.childElementCount === 0;
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

function renderVenueMap(venue, trigger) {
    const canvas = modal.querySelector('[data-entity-preview-map]');
    if (!canvas) return;

    const latitude = Number.parseFloat(trigger?.dataset.entityPreviewLatitude || '');
    const longitude = Number.parseFloat(trigger?.dataset.entityPreviewLongitude || '');
    const apiKey = trigger?.dataset.yandexMapApiKey || '';

    if (!Number.isFinite(latitude) || !Number.isFinite(longitude) || !apiKey) {
        canvas.hidden = true;
        return;
    }

    canvas.hidden = false;
    const center = [latitude, longitude];

    loadYandexMaps(apiKey)
        .then(() => new Promise((resolve) => window.ymaps.ready(resolve)))
        .then(() => {
            if (canvas.hidden) return;

            previewMap?.destroy();
            previewMap = new window.ymaps.Map(canvas, {
                center,
                zoom: 15,
                controls: ['zoomControl', 'fullscreenControl'],
            });
            previewMap.geoObjects.add(new window.ymaps.Placemark(center, {
                hintContent: venue.name || 'Площадка',
                balloonContentHeader: venue.name || 'Площадка',
                balloonContentBody: venue.address || '',
            }, {
                preset: 'islands#orangeSportIcon',
            }));

            window.requestAnimationFrame(() => previewMap?.container.fitToViewport());
        })
        .catch(() => {
            canvas.hidden = true;
        });
}

function resetMap() {
    const canvas = modal?.querySelector('[data-entity-preview-map]');
    previewMap?.destroy();
    previewMap = null;
    if (canvas) {
        canvas.hidden = true;
        canvas.innerHTML = '';
    }
}
