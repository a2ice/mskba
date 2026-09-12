import { loadYandexMaps } from '../core/yandex-maps.js';
import '../../css/pages/venue-catalog-fixes.css';

const MOSCOW_METRO_AREA_BOUNDS = [
    [55.25, 36.75],
    [56.05, 38.25],
];

document.addEventListener('DOMContentLoaded', () => {
    const catalog = document.querySelector('[data-default-category].venues-catalog');
    if (!catalog) return;

    let mapPromise = null;
    const initMap = () => initCatalogMap(catalog, () => mapPromise, (promise) => { mapPromise = promise; });

    catalog.addEventListener('default-category:viewchange', (event) => {
        if (event.detail?.view === 'map') initMap();
    });

    if (catalog.dataset.defaultCategoryView === 'map') initMap();
});

function initCatalogMap(catalog, getPromise, setPromise) {
    if (getPromise()) return;
    const canvas = catalog.querySelector('[data-venue-catalog-map]');
    const pointsNode = catalog.querySelector('[data-venue-catalog-map-points]');
    const status = catalog.querySelector('[data-venue-catalog-map-status]');
    if (!canvas || !pointsNode) return;

    let points = [];
    try { points = JSON.parse(pointsNode.textContent || '[]'); } catch { points = []; }
    const apiKey = canvas.dataset.yandexMapApiKey;
    if (!apiKey || points.length === 0) {
        if (status) status.textContent = apiKey ? 'Нет площадок с координатами.' : 'Ключ Яндекс Карт не настроен.';
        return;
    }

    const promise = loadYandexMaps(apiKey)
        .then(() => new Promise((resolve) => window.ymaps.ready(resolve)))
        .then(() => {
            const map = new window.ymaps.Map(canvas, {
                bounds: MOSCOW_METRO_AREA_BOUNDS,
                controls: ['zoomControl', 'fullscreenControl', 'geolocationControl'],
            });
            const placemarks = points.map((point) => {
                const coordinates = [point.latitude, point.longitude];
                return new window.ymaps.Placemark(coordinates, {
                    hintContent: point.name,
                    balloonContentHeader: point.name,
                    balloonContentBody: `${escapeHtml(point.address || '')}<br><a href="${escapeHtml(point.url)}">Открыть площадку</a>`,
                }, { preset: 'islands#orangeSportIcon' });
            });
            const clusterer = new window.ymaps.Clusterer({
                preset: 'islands#invertedOrangeClusterIcons',
                groupByCoordinates: false,
                gridSize: 64,
                clusterDisableClickZoom: false,
                clusterOpenBalloonOnClick: false,
                clusterHideIconOnBalloonOpen: false,
                geoObjectHideIconOnBalloonOpen: false,
            });
            clusterer.add(placemarks);
            map.geoObjects.add(clusterer);
            map.setBounds(MOSCOW_METRO_AREA_BOUNDS, { checkZoomRange: true, zoomMargin: 18 });
            if (status) status.hidden = true;
            window.setTimeout(() => map.container.fitToViewport(), 0);
        })
        .catch(() => {
            if (status) status.textContent = 'Не удалось загрузить карту.';
        });

    setPromise(promise);
}

function escapeHtml(value) {
    const node = document.createElement('span');
    node.textContent = String(value);
    return node.innerHTML;
}
