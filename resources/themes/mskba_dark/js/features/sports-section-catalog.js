import { loadYandexMaps } from '../core/yandex-maps.js';
import '../../css/pages/sports-section-catalog.css';

const SINGLE_POINT_ZOOM = 14;
const MAX_AUTO_ZOOM = 16;
const MAP_MARGIN = 44;

document.addEventListener('DOMContentLoaded', () => {
    const catalog = document.querySelector('[data-default-category].sports-sections-category-catalog');
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

    const canvas = catalog.querySelector('[data-sports-section-category-map]');
    const pointsNode = catalog.querySelector('[data-sports-section-category-map-points]');
    const status = catalog.querySelector('[data-sports-section-category-map-status]');
    if (!canvas || !pointsNode) return;

    let points = [];
    try {
        points = JSON.parse(pointsNode.textContent || '[]');
    } catch {
        points = [];
    }

    const apiKey = canvas.dataset.yandexMapApiKey;
    if (!apiKey || points.length === 0) {
        if (status) status.textContent = apiKey ? 'Нет секций с координатами основной площадки.' : 'Ключ Яндекс Карт не настроен.';
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

            const clusterer = new window.ymaps.Clusterer({
                preset: 'islands#invertedOrangeClusterIcons',
                gridSize: 64,
                groupByCoordinates: false,
            });
            clusterer.add(placemarks);
            map.geoObjects.add(clusterer);

            window.setTimeout(() => {
                map.container.fitToViewport();
                fitMap(map, groups);
            }, 0);

            if (status) status.hidden = true;
        })
        .catch(() => {
            if (status) status.textContent = 'Не удалось загрузить карту.';
        });

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
                sections: [],
            });
        }

        const section = point.section;
        if (section && !grouped.get(key).sections.some((item) => Number(item.id) === Number(section.id))) {
            grouped.get(key).sections.push(section);
        }
    });

    return Array.from(grouped.values());
}

function fitMap(map, groups) {
    if (groups.length <= 1) {
        const point = groups[0];
        map.setCenter([point.latitude, point.longitude], SINGLE_POINT_ZOOM, { checkZoomRange: true });
        return;
    }

    const latitudes = groups.map((point) => point.latitude);
    const longitudes = groups.map((point) => point.longitude);
    const fitting = map.setBounds([
        [Math.min(...latitudes), Math.min(...longitudes)],
        [Math.max(...latitudes), Math.max(...longitudes)],
    ], { checkZoomRange: true, zoomMargin: MAP_MARGIN });

    if (fitting && typeof fitting.then === 'function') {
        fitting.then(() => {
            if (map.getZoom() > MAX_AUTO_ZOOM) map.setZoom(MAX_AUTO_ZOOM);
        });
    }
}

function renderBalloon(group) {
    const sections = group.sections.map((section) => {
        const name = escapeMarkup(section.name || 'Секция');
        const url = escapeMarkup(section.url || '#');
        const meta = escapeMarkup([section.format, section.training_mode, section.pricing].filter(Boolean).join(' · '));
        const recruitment = section.recruitment_text
            ? `<span class="sports-section-category-map-balloon__badge${section.recruiting ? '' : ' sports-section-category-map-balloon__badge--applications'}">${escapeMarkup(section.recruitment_text)}</span>`
            : '';
        const badgesHtml = recruitment ? `<div class="sports-section-category-map-balloon__badges">${recruitment}</div>` : '';

        return `<div class="sports-section-category-map-balloon__item"><a href="${url}">${name}</a><small>${meta}</small>${badgesHtml}</div>`;
    }).join('');
    const address = group.address ? `<div class="sports-section-category-map-balloon__address">${escapeMarkup(group.address)}</div>` : '';

    return `<div class="sports-section-category-map-balloon"><div class="sports-section-category-map-balloon__venue">${escapeMarkup(group.venueName)}</div>${address}<div class="sports-section-category-map-balloon__items">${sections}</div></div>`;
}

function escapeMarkup(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}
