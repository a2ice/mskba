import { loadYandexMaps } from '../../mskba_dark/js/core/yandex-maps.js';
import { venuePlacemark } from '../../mskba_dark/js/core/venue-map-pins.js';

/* Optional numeric catalog filters share the player-profile range control. */
document.querySelectorAll('[data-catalog-range-filter]').forEach(root => {
    const toggle = root.querySelector('[data-catalog-range-toggle]');
    const options = root.querySelector('[data-catalog-range-options]');
    const range = root.querySelector('[data-catalog-range-input]');
    const value = root.querySelector('[data-catalog-range-value]');
    if (!toggle || !options || !range || !value) return;
    const sync = () => {
        options.hidden = !toggle.checked;
        range.disabled = !toggle.checked;
        value.textContent = range.value;
    };
    toggle.addEventListener('change', sync);
    range.addEventListener('input', sync);
    window.addEventListener('pageshow', sync);
    sync();
});

/* Venue infinite scrolling. Use the existing project loading spinner. */
(() => {
    const root = document.querySelector('[data-venue-infinite-catalog]');
    if (!root) return;
    const items = root.querySelector('[data-venue-items]');
    const sentinel = root.querySelector('[data-venue-sentinel]');
    const spinner = root.querySelector('[data-venue-loader]');
    const error = root.querySelector('[data-venue-load-error]');
    const retry = root.querySelector('[data-venue-retry]');
    const counter = root.querySelector('[data-venue-visible-count]');
    if (!items || !sentinel || !spinner || !error || !counter) return;

    let nextPage = Number(root.dataset.nextPage) || null;
    let busy = false;
    let failed = false;
    const ids = new Set([...items.querySelectorAll('[data-venue-id]')].map(el => el.dataset.venueId));

    async function load() {
        if (busy || failed || !nextPage || document.visibilityState === 'hidden') return;
        busy = true;
        spinner.hidden = false;
        error.hidden = true;
        root.setAttribute('aria-busy', 'true');
        try {
            const url = new URL(location.href);
            url.searchParams.set('page', String(nextPage));
            url.searchParams.set('fragment', 'items');
            const response = await fetch(url, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            if (!response.ok) throw new Error('HTTP ' + response.status);
            const result = await response.json();
            if (typeof result.html !== 'string' || typeof result.has_more !== 'boolean') {
                throw new Error('Invalid response');
            }
            const template = document.createElement('template');
            template.innerHTML = result.html;
            for (const child of [...template.content.children]) {
                if (child.matches('[data-venue-id]')) {
                    if (ids.has(child.dataset.venueId)) continue;
                    ids.add(child.dataset.venueId);
                }
                items.append(child);
            }
            counter.textContent = String(ids.size);
            nextPage = result.has_more && Number.isInteger(Number(result.next_page))
                ? Number(result.next_page) : null;
            if (!nextPage) observer.disconnect();
        } catch {
            failed = true;
            error.hidden = false;
        } finally {
            busy = false;
            spinner.hidden = true;
            root.removeAttribute('aria-busy');
            if (!failed && nextPage && sentinel.getBoundingClientRect().top < innerHeight + 300) {
                requestAnimationFrame(() => void load());
            }
        }
    }

    const observer = new IntersectionObserver(entries => {
        if (entries.some(entry => entry.isIntersecting)) void load();
    }, { rootMargin: '300px 0px' });
    if (nextPage) observer.observe(sentinel);
    retry?.addEventListener('click', () => { failed = false; void load(); });
})();


/* Map mode renders every access-aware, filtered venue (including venues beyond
 * the initial card page). Yandex clusters are rebuilt for each GET-filter result.
 * The map is only initialized when the map view is actually displayed. */
(() => {
    const frame = document.querySelector('[data-venue-catalog-map-frame]');
    const canvas = frame?.querySelector('[data-venue-catalog-map]');
    const pointsNode = frame?.querySelector('[data-venue-catalog-map-points]');
    const status = frame?.querySelector('[data-venue-catalog-map-status]');
    if (!frame || !canvas || !pointsNode || !status) return;

    const escapeHtml = value => {
        const node = document.createElement('span');
        node.textContent = String(value ?? '');
        return node.innerHTML;
    };
    let points;
    try { points = JSON.parse(pointsNode.textContent || '[]'); }
    catch { points = []; }
    points = (Array.isArray(points) ? points : []).filter(point => (
        point.latitude !== null && point.longitude !== null
        && Number.isFinite(Number(point.latitude))
        && Number.isFinite(Number(point.longitude))
        && Math.abs(Number(point.latitude)) <= 90
        && Math.abs(Number(point.longitude)) <= 180
    ));

    // A long infinite-scroll list may have left the browser far below the
    // map's position. Correct the restored scroll before Yandex measures it.
    const restoreMapViewport = () => {
        const rect = frame.getBoundingClientRect();
        const stickyOffset = parseFloat(getComputedStyle(document.documentElement)
            .getPropertyValue('--app-sticky-offset')) || 0;
        const safeTop = Math.max(16, stickyOffset + 14);
        if (rect.top < safeTop || rect.top > window.innerHeight - 120) {
            window.scrollTo({
                top: Math.max(0, window.scrollY + rect.top - safeTop),
                behavior: 'instant',
            });
        }
    };
    const afterPaint = callback => requestAnimationFrame(() => requestAnimationFrame(callback));
    afterPaint(restoreMapViewport);
    // The browser can restore scroll after DOMContentLoaded (notably Safari).
    window.addEventListener('pageshow', () => afterPaint(restoreMapViewport));

    if (!canvas.dataset.yandexMapApiKey) {
        status.textContent = 'Ключ Яндекс Карт не настроен.';
        return;
    }
    if (points.length === 0) {
        status.textContent = 'Нет площадок с координатами.';
        return;
    }

    const coords = point => [Number(point.latitude), Number(point.longitude)];
    const first = coords(points[0]);

    const fitPoints = map => {
        map.container.fitToViewport();
        const latitudes = points.map(point => Number(point.latitude));
        const longitudes = points.map(point => Number(point.longitude));
        const bounds = [
            [Math.min(...latitudes), Math.min(...longitudes)],
            [Math.max(...latitudes), Math.max(...longitudes)],
        ];
        if (bounds[0][0] === bounds[1][0] && bounds[0][1] === bounds[1][1]) {
            map.setCenter(first, 15);
        } else {
            Promise.resolve(map.setBounds(bounds, {
                checkZoomRange: true,
                // Fractional zoom prevents an extra full zoom level of empty
                // space around the outermost venue pins.
                preciseZoom: true,
                zoomMargin: 28,
            })).then(() => {
                if (map.getZoom() > 16) map.setZoom(16);
            }).catch(() => {});
        }
    };

    const bodyFor = point => {
        const sections = [];
        if (point.address) sections.push('<div>' + escapeHtml(point.address) + '</div>');
        if (point.matched_court) {
            sections.push('<div>Подходящий зал: ' + escapeHtml(point.matched_court) + '</div>');
        }
        if (point.free_courts) sections.push('<div>Есть бесплатный доступ</div>');
        if (point.paid_courts) sections.push('<div>Есть платные залы</div>');
        if (point.approval_courts) sections.push('<div>Есть залы с подтверждением</div>');
        sections.push('<a href="' + escapeHtml(point.url) + '">' +
            (point.matched_court ? 'Открыть зал' : 'Открыть площадку') + '</a>');
        if (Number.isSafeInteger(Number(point.id)) && Number(point.id) > 0) {
            sections.push('<button type="button" class="mskba-favorite-heart" data-favorite-venue="' +
                Number(point.id) + '" title="Добавить в избранное" aria-label="Добавить в избранное" aria-pressed="false">♡</button>');
        }
        return sections.join('');
    };

    loadYandexMaps(canvas.dataset.yandexMapApiKey)
        .then(() => new Promise(resolve => window.ymaps.ready(resolve)))
        .then(() => {
            const map = new window.ymaps.Map(canvas, {
                center: first,
                zoom: points.length === 1 ? 15 : 10,
                controls: ['zoomControl', 'fullscreenControl', 'geolocationControl'],
            });
            const clusterer = new window.ymaps.Clusterer({
                preset: 'islands#invertedOrangeClusterIcons',
                gridSize: 64,
                groupByCoordinates: false,
                clusterDisableClickZoom: false,
                clusterOpenBalloonOnClick: false,
                clusterHideIconOnBalloonOpen: false,
                geoObjectHideIconOnBalloonOpen: false,
            });
            clusterer.add(points.map(point => venuePlacemark(coords(point), {
                hintContent: escapeHtml(point.name),
                balloonContentHeader: escapeHtml(point.name),
                balloonContentBody: bodyFor(point),
            })));
            map.geoObjects.add(clusterer);
            status.hidden = true;
            afterPaint(() => fitPoints(map));
            window.addEventListener('resize', () => afterPaint(() => fitPoints(map)), { passive: true });
        })
        .catch(() => {
            status.hidden = false;
            status.textContent = 'Не удалось загрузить карту. Попробуйте обновить страницу.';
        });
})();
