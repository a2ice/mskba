import { loadYandexMaps } from '../core/yandex-maps.js';

document.addEventListener('DOMContentLoaded', () => {
    const maps = Array.from(document.querySelectorAll('[data-venue-map]'));

    if (maps.length === 0) {
        return;
    }

    const firstMap = maps[0];
    const apiKey = firstMap.dataset.yandexMapApiKey;

    if (!apiKey) {
        maps.forEach((map) => showFallback(map, 'Ключ Яндекс Карт не настроен.'));
        return;
    }

    loadYandexMaps(apiKey)
        .then(() => {
            window.ymaps.ready(() => {
                maps.forEach(initVenueMap);
            });
        })
        .catch(() => {
            maps.forEach((map) => showFallback(map, 'Не удалось загрузить карту.'));
        });
});

function initVenueMap(map) {
    const latitude = Number.parseFloat(map.dataset.latitude || '');
    const longitude = Number.parseFloat(map.dataset.longitude || '');

    if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
        showFallback(map, 'Координаты площадки пока не указаны.');
        return;
    }

    const center = [latitude, longitude];
    const title = map.dataset.title || 'Площадка';
    const address = map.dataset.address || '';
    const yandexMap = new window.ymaps.Map(map, {
        center,
        zoom: 15,
        controls: ['zoomControl', 'fullscreenControl'],
    });
    const currentPlacemark = new window.ymaps.Placemark(center, {
        balloonContentHeader: title,
        balloonContentBody: address,
        hintContent: title,
    }, {
        preset: 'islands#orangeSportIcon',
    });

    yandexMap.geoObjects.add(currentPlacemark);
    initNearbyVenues(map, yandexMap, currentPlacemark, center);
}

function initNearbyVenues(map, yandexMap, currentPlacemark, center) {
    const section = map.closest('#address');
    const button = section?.querySelector('[data-venue-nearby-open]');
    const payload = section?.querySelector('[data-venue-nearby-points]');

    if (!button || !payload) {
        return;
    }

    let points = [];
    try {
        points = JSON.parse(payload.textContent || '[]');
    } catch (error) {
        points = [];
    }

    points = points.filter((point) => Number.isFinite(Number(point.latitude))
        && Number.isFinite(Number(point.longitude))
        && point.preview_url);

    if (points.length === 0) {
        button.disabled = true;
        return;
    }

    const nearbyCollection = new window.ymaps.GeoObjectCollection();
    points.forEach((point) => {
        const placemark = new window.ymaps.Placemark(
            [Number(point.latitude), Number(point.longitude)],
            { hintContent: point.name || 'Площадка рядом' },
            { preset: 'islands#blueSportIcon' },
        );

        placemark.events.add('click', (event) => {
            event.preventDefault();
            openVenuePreview(point.preview_url);
        });
        nearbyCollection.add(placemark);
    });

    let isVisible = false;
    button.disabled = false;
    button.addEventListener('click', () => {
        isVisible = !isVisible;
        button.setAttribute('aria-pressed', String(isVisible));

        if (isVisible) {
            yandexMap.geoObjects.add(nearbyCollection);
            const bounds = yandexMap.geoObjects.getBounds();
            if (bounds) {
                yandexMap.setBounds(bounds, {
                    checkZoomRange: true,
                    zoomMargin: [48, 48, 48, 48],
                });
            }
            return;
        }

        yandexMap.geoObjects.remove(nearbyCollection);
        yandexMap.setCenter(center, 15, { checkZoomRange: true });
        currentPlacemark.balloon.close();
    });
}

function openVenuePreview(url) {
    const trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.hidden = true;
    trigger.dataset.handler = 'modal';
    trigger.dataset.modalAction = 'open';
    trigger.dataset.modalTarget = 'embedded-entity-preview';
    trigger.dataset.entityPreviewTrigger = '';
    trigger.dataset.entityPreviewUrl = url;
    document.body.append(trigger);
    trigger.click();
    trigger.remove();
}

function showFallback(map, message) {
    const fallback = map.closest('[data-venue-map-frame]')?.querySelector('[data-venue-map-fallback]');

    map.hidden = true;

    if (fallback) {
        fallback.hidden = false;
        const messageElement = fallback.querySelector('[data-venue-map-fallback-message]');

        if (messageElement) {
            messageElement.textContent = message;
        }
    }
}
