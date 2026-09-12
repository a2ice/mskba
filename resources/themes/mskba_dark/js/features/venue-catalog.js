import { loadYandexMaps } from '../core/yandex-maps.js';
import '../../css/pages/venue-catalog-fixes.css';

const SINGLE_POINT_ZOOM = 15;
const MAP_ZOOM_MARGIN = [40, 40, 40, 40];

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

    const mapPoints = points
        .map((point) => ({
            ...point,
            latitude: Number(point.latitude),
            longitude: Number(point.longitude),
        }))
        .filter((point) => Number.isFinite(point.latitude) && Number.isFinite(point.longitude));

    const apiKey = canvas.dataset.yandexMapApiKey;
    if (!apiKey || mapPoints.length === 0) {
        if (status) status.textContent = apiKey ? 'Нет площадок с координатами.' : 'Ключ Яндекс Карт не настроен.';
        return;
    }

    const firstCoordinates = [mapPoints[0].latitude, mapPoints[0].longitude];

    const promise = loadYandexMaps(apiKey)
        .then(() => new Promise((resolve) => window.ymaps.ready(resolve)))
        .then(() => {
            const map = new window.ymaps.Map(canvas, {
                center: firstCoordinates,
                zoom: mapPoints.length === 1 ? SINGLE_POINT_ZOOM : 10,
                controls: ['zoomControl', 'fullscreenControl', 'geolocationControl'],
            });
            const placemarks = mapPoints.map((point) => {
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

            if (status) status.hidden = true;

            window.setTimeout(() => {
                map.container.fitToViewport();
                fitMapToPoints(map, mapPoints);
            }, 0);
        })
        .catch(() => {
            if (status) status.textContent = 'Не удалось загрузить карту.';
        });

    setPromise(promise);
}

function fitMapToPoints(map, points) {
    const first = [points[0].latitude, points[0].longitude];
    const latitudes = points.map((point) => point.latitude);
    const longitudes = points.map((point) => point.longitude);
    const bounds = [
        [Math.min(...latitudes), Math.min(...longitudes)],
        [Math.max(...latitudes), Math.max(...longitudes)],
    ];
    const hasArea = bounds[0][0] !== bounds[1][0] || bounds[0][1] !== bounds[1][1];

    if (!hasArea) {
        map.setCenter(first, SINGLE_POINT_ZOOM);
        return;
    }

    map.setBounds(bounds, {
        checkZoomRange: true,
        zoomMargin: MAP_ZOOM_MARGIN,
    });
}

function escapeHtml(value) {
    const node = document.createElement('span');
    node.textContent = String(value);
    return node.innerHTML;
}
