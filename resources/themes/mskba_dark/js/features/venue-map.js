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

    yandexMap.geoObjects.add(new window.ymaps.Placemark(center, {
        balloonContentHeader: title,
        balloonContentBody: address,
        hintContent: title,
    }, {
        preset: 'islands#orangeSportIcon',
    }));
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
