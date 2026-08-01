import { loadYandexMaps } from '../core/yandex-maps.js';

const MOSCOW_REGION_BOUNDS = [
    [54.20, 35.10],
    [57.05, 40.35],
];

document.addEventListener('DOMContentLoaded', () => {
    const catalog = document.querySelector('[data-venue-catalog]');
    if (!catalog) return;

    const filters = catalog.querySelector('[data-venue-filters]');
    const filterToggle = catalog.querySelector('[data-venue-filter-toggle]');
    const filterIcon = catalog.querySelector('[data-venue-filter-toggle-icon]');
    const list = catalog.querySelector('[data-venue-list]');
    const mapFrame = catalog.querySelector('[data-venue-catalog-map-frame]');
    const viewInput = catalog.querySelector('[data-venue-view-input]');
    const viewButtons = Array.from(catalog.querySelectorAll('[data-venue-view]'));
    let mapPromise = null;

    filterToggle?.addEventListener('click', () => {
        const open = filters.hidden;
        filters.hidden = !open;
        filterToggle.setAttribute('aria-expanded', String(open));
        filterToggle.closest('.venues-catalog-toolbar')?.classList.toggle('is-filters-collapsed', !open);
        filterIcon?.classList.toggle('ti-chevron-up', open);
        filterIcon?.classList.toggle('ti-chevron-down', !open);
    });

    viewButtons.forEach((button) => button.addEventListener('click', () => {
        const view = button.dataset.venueView;
        const showMap = view === 'map';
        list.hidden = showMap;
        mapFrame.hidden = !showMap;
        if (viewInput) viewInput.value = view;
        viewButtons.forEach((item) => {
            const active = item === button;
            item.classList.toggle('is-active', active);
            item.setAttribute('aria-pressed', String(active));
        });
        const url = new URL(window.location.href);
        url.searchParams.set('view', view);
        window.history.replaceState({}, '', url);
        if (showMap) initCatalogMap(catalog, () => mapPromise, (promise) => { mapPromise = promise; });
    }));

    if (!mapFrame?.hidden) initCatalogMap(catalog, () => mapPromise, (promise) => { mapPromise = promise; });
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

    const promise = loadYandexMaps(apiKey).then(() => new Promise((resolve) => window.ymaps.ready(resolve))).then(() => {
        const map = new window.ymaps.Map(canvas, {
            bounds: MOSCOW_REGION_BOUNDS,
            controls: ['zoomControl', 'fullscreenControl', 'geolocationControl'],
        });
        points.forEach((point) => {
            const coordinates = [point.latitude, point.longitude];
            map.geoObjects.add(new window.ymaps.Placemark(coordinates, {
                hintContent: point.name,
                balloonContentHeader: point.name,
                balloonContentBody: `${escapeHtml(point.address || '')}<br><a href="${escapeHtml(point.url)}">Открыть площадку</a>`,
            }, { preset: 'islands#orangeSportIcon' }));
        });
        map.setBounds(MOSCOW_REGION_BOUNDS, { checkZoomRange: true, zoomMargin: 24 });
        if (status) status.hidden = true;
        window.setTimeout(() => map.container.fitToViewport(), 0);
    }).catch(() => { if (status) status.textContent = 'Не удалось загрузить карту.'; });
    setPromise(promise);
}

function escapeHtml(value) {
    const node = document.createElement('span');
    node.textContent = String(value);
    return node.innerHTML;
}
