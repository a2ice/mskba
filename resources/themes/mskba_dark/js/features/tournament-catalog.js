import { loadYandexMaps } from '../core/yandex-maps.js';
import '../../css/pages/tournament-catalog.css';

const SINGLE_POINT_ZOOM = 14;
const MAX_AUTO_ZOOM = 16;
const MAP_MARGIN = 44;

document.addEventListener('DOMContentLoaded', () => {
    const catalog = document.querySelector('[data-default-category].tournaments-category-catalog');
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

    const canvas = catalog.querySelector('[data-tournament-category-map]');
    const pointsNode = catalog.querySelector('[data-tournament-category-map-points]');
    const status = catalog.querySelector('[data-tournament-category-map-status]');
    if (!canvas || !pointsNode) return;

    let points = [];
    try { points = JSON.parse(pointsNode.textContent || '[]'); } catch { points = []; }

    const apiKey = canvas.dataset.yandexMapApiKey;
    if (!apiKey || points.length === 0) {
        if (status) status.textContent = apiKey ? 'Нет турниров с координатами.' : 'Ключ Яндекс Карт не настроен.';
        return;
    }

    const groups = groupByCoordinates(points);
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

            const clusterer = new window.ymaps.Clusterer({ preset: 'islands#invertedOrangeClusterIcons', gridSize: 64 });
            clusterer.add(placemarks);
            map.geoObjects.add(clusterer);

            window.setTimeout(() => {
                map.container.fitToViewport();
                fitMap(map, groups);
            }, 0);

            if (status) status.hidden = true;
        })
        .catch(() => { if (status) status.textContent = 'Не удалось загрузить карту.'; });

    setPromise(promise);
}

function groupByCoordinates(points) {
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
                tournaments: [],
            });
        }
        if (point.tournament) grouped.get(key).tournaments.push(point.tournament);
    });
    return Array.from(grouped.values());
}

function fitMap(map, groups) {
    if (groups.length <= 1) {
        map.setCenter([groups[0].latitude, groups[0].longitude], SINGLE_POINT_ZOOM, { checkZoomRange: true });
        return;
    }

    const latitudes = groups.map((point) => point.latitude);
    const longitudes = groups.map((point) => point.longitude);
    const fitting = map.setBounds([
        [Math.min(...latitudes), Math.min(...longitudes)],
        [Math.max(...latitudes), Math.max(...longitudes)],
    ], { checkZoomRange: true, zoomMargin: MAP_MARGIN });

    if (fitting && typeof fitting.then === 'function') {
        fitting.then(() => { if (map.getZoom() > MAX_AUTO_ZOOM) map.setZoom(MAX_AUTO_ZOOM); });
    }
}

function renderBalloon(group) {
    const items = group.tournaments.map((tournament) => {
        const title = escapeMarkup(tournament.title || 'Турнир');
        const phase = escapeMarkup(tournament.phase || '');
        const dates = escapeMarkup(tournament.dates || '');
        const url = escapeMarkup(tournament.url || '#');
        return `<div class="tournament-category-map-balloon__item"><a href="${url}">${title}</a><small>${phase}${dates ? ` · ${dates}` : ''}</small></div>`;
    }).join('');
    const address = group.address ? `<div class="tournament-category-map-balloon__address">${escapeMarkup(group.address)}</div>` : '';
    return `<div class="tournament-category-map-balloon"><div class="tournament-category-map-balloon__venue">${escapeMarkup(group.venueName)}</div>${address}<div class="tournament-category-map-balloon__items">${items}</div></div>`;
}

function escapeMarkup(value) {
    return String(value).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
}
