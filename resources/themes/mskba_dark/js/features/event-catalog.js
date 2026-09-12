import { loadYandexMaps } from '../core/yandex-maps.js';
import '../../css/pages/event-catalog.css';

const SINGLE_POINT_ZOOM = 14;
const MAX_AUTO_ZOOM = 16;
const MAP_MARGIN = 44;

document.addEventListener('DOMContentLoaded', () => {
    const catalog = document.querySelector('[data-default-category].events-category-catalog');
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

    const canvas = catalog.querySelector('[data-event-category-map]');
    const pointsNode = catalog.querySelector('[data-event-category-map-points]');
    const status = catalog.querySelector('[data-event-category-map-status]');
    if (!canvas || !pointsNode) return;

    let points = [];
    try {
        points = JSON.parse(pointsNode.textContent || '[]');
    } catch {
        points = [];
    }

    const apiKey = canvas.dataset.yandexMapApiKey;
    if (!apiKey || points.length === 0) {
        if (status) status.textContent = apiKey ? 'Нет мероприятий с координатами.' : 'Ключ Яндекс Карт не настроен.';
        return;
    }

    const groups = groupEventsByCoordinates(points);
    const first = groups[0];
    const promise = loadYandexMaps(apiKey)
        .then(() => new Promise((resolve) => window.ymaps.ready(resolve)))
        .then(() => {
            const map = new window.ymaps.Map(canvas, {
                center: [first.latitude, first.longitude],
                zoom: SINGLE_POINT_ZOOM,
                controls: ['zoomControl', 'fullscreenControl', 'geolocationControl'],
            });

            const placemarks = groups.map((group) => new window.ymaps.Placemark(
                [group.latitude, group.longitude],
                {
                    hintContent: group.venueName,
                    balloonContentHeader: group.venueName,
                    balloonContentBody: renderBalloon(group),
                },
                { preset: 'islands#orangeSportIcon' },
            ));

            const clusterer = new window.ymaps.Clusterer({
                preset: 'islands#invertedOrangeClusterIcons',
                groupByCoordinates: false,
                gridSize: 64,
                clusterDisableClickZoom: false,
                clusterOpenBalloonOnClick: true,
                clusterHideIconOnBalloonOpen: false,
                geoObjectHideIconOnBalloonOpen: false,
            });

            clusterer.add(placemarks);
            map.geoObjects.add(clusterer);

            window.setTimeout(() => {
                map.container.fitToViewport();
                fitMapToGroups(map, groups);
            }, 0);

            if (status) status.hidden = true;
        })
        .catch(() => {
            if (status) status.textContent = 'Не удалось загрузить карту.';
        });

    setPromise(promise);
}

function groupEventsByCoordinates(points) {
    const grouped = new Map();

    points.forEach((point) => {
        const latitude = Number(point.latitude);
        const longitude = Number(point.longitude);
        if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) return;

        const key = `${latitude.toFixed(6)}:${longitude.toFixed(6)}`;
        if (!grouped.has(key)) {
            grouped.set(key, {
                latitude,
                longitude,
                venueName: String(point.venue_name || 'Площадка'),
                address: String(point.address || ''),
                events: [],
            });
        }

        if (point.event) grouped.get(key).events.push(point.event);
    });

    return Array.from(grouped.values());
}

function fitMapToGroups(map, groups) {
    if (groups.length <= 1) {
        const point = groups[0];
        map.setCenter([point.latitude, point.longitude], SINGLE_POINT_ZOOM, { checkZoomRange: true });
        return;
    }

    const latitudes = groups.map((point) => point.latitude);
    const longitudes = groups.map((point) => point.longitude);
    const bounds = [
        [Math.min(...latitudes), Math.min(...longitudes)],
        [Math.max(...latitudes), Math.max(...longitudes)],
    ];

    const fitting = map.setBounds(bounds, { checkZoomRange: true, zoomMargin: MAP_MARGIN });
    if (fitting && typeof fitting.then === 'function') {
        fitting.then(() => {
            if (map.getZoom() > MAX_AUTO_ZOOM) map.setZoom(MAX_AUTO_ZOOM);
        });
    }
}

function renderBalloon(group) {
    const events = group.events
        .map((event) => `${escapeMarkup(event.title || 'Мероприятие')} — ${escapeMarkup(event.type || '')}${event.starts_at ? ` · ${escapeMarkup(event.starts_at)}` : ''}`)
        .join('<br><br>');

    const address = group.address ? `<div class="event-category-map-balloon__address">${escapeMarkup(group.address)}</div>` : '';
    return `<div class="event-category-map-balloon"><div class="event-category-map-balloon__venue">${escapeMarkup(group.venueName)}</div>${address}<div class="event-category-map-balloon__events">${events}</div></div>`;
}

function escapeMarkup(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}
