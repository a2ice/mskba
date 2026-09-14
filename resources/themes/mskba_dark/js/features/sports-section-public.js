import { loadYandexMaps } from '../core/yandex-maps.js';

let sectionVenueMap = null;

function initSectionVenueMap() {
    const canvas = document.querySelector('[data-section-venue-map]');
    if (!canvas) return;

    const latitude = Number(canvas.dataset.latitude);
    const longitude = Number(canvas.dataset.longitude);
    const apiKey = canvas.dataset.yandexMapApiKey || '';
    const title = canvas.dataset.title || 'Основная площадка';

    if (!Number.isFinite(latitude) || !Number.isFinite(longitude) || !apiKey) return;

    if (sectionVenueMap) {
        window.requestAnimationFrame(() => sectionVenueMap.container.fitToViewport());
        return;
    }

    loadYandexMaps(apiKey)
        .then(() => new Promise((resolve) => window.ymaps.ready(resolve)))
        .then(() => {
            const center = [latitude, longitude];
            sectionVenueMap = new window.ymaps.Map(canvas, {
                center,
                zoom: 15,
                controls: ['zoomControl', 'fullscreenControl'],
            });
            sectionVenueMap.geoObjects.add(new window.ymaps.Placemark(center, {
                balloonContent: title,
                hintContent: title,
            }, {
                preset: 'islands#orangeSportIcon',
            }));

            window.requestAnimationFrame(() => {
                window.requestAnimationFrame(() => sectionVenueMap?.container.fitToViewport());
            });
        })
        .catch(() => {});
}

document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-modal-target="section-primary-venue-map"]');
    if (!trigger) return;

    window.setTimeout(initSectionVenueMap, 0);
});
