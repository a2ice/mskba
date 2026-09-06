import { loadYandexMaps } from '../core/yandex-maps.js';
import '../../css/pages/home-venue-section.css';

const section = document.querySelector('#venues');
const source = document.querySelector('[data-home-venue-section-selector-source]');
const selector = source?.querySelector(':scope > [data-venue-selector]');
const shell = section?.querySelector('.home-venue-search-shell');
const mapPreview = section?.querySelector('.home-venue-map-preview');

if (section && source && selector && shell && mapPreview) {
    mountSharedSelector();
    initInlineMap();
}

function mountSharedSelector() {
    shell.replaceChildren(selector);
    shell.classList.add('home-venue-search-shell--live');
    selector.classList.add('home-venue-section-selector');

    const links = selector.querySelector('.venue-selector__links');
    if (!links || links.querySelector('[data-home-venue-advanced-filters]')) {
        return;
    }

    const filterButton = document.createElement('button');
    filterButton.type = 'button';
    filterButton.className = 'fc-link home-venue-section__filter-link';
    filterButton.dataset.homeVenueAdvancedFilters = '';
    filterButton.innerHTML = '<i class="ti ti-adjustments-horizontal" aria-hidden="true"></i> Фильтры';
    filterButton.addEventListener('click', () => {
        document.querySelector(
            '[data-modal-target="home-venue-flow"][data-modal-section="search"]',
        )?.click();
    });
    links.append(filterButton);
}

function initInlineMap() {
    const mapSource = source.querySelector('[data-venue-selector-map]');
    const apiKey = mapSource?.dataset.yandexMapApiKey || '';
    const searchUrl = selector.dataset.searchUrl || '';
    const popularVenuePaths = new Set(
        Array.from(section.querySelectorAll('.home-venue-stack .home-venue-card'))
            .map((link) => normalizeUrlPath(link.getAttribute('href')))
            .filter(Boolean),
    );

    mapPreview.classList.add('home-venue-map-preview--live');
    mapPreview.setAttribute('aria-label', 'Карта популярных площадок');
    mapPreview.replaceChildren();

    const canvas = document.createElement('div');
    canvas.className = 'home-venue-map-preview__canvas';

    const status = document.createElement('p');
    status.className = 'home-venue-map-preview__status';
    status.textContent = 'Загружаем популярные площадки…';

    const caption = document.createElement('div');
    caption.className = 'home-venue-map-preview__caption home-venue-map-preview__caption--live';
    caption.innerHTML = '<i class="ti ti-map-2"></i><strong>Популярные площадки</strong><small>На карте — только площадки из подборки ниже</small>';

    mapPreview.append(canvas, status, caption);

    if (!apiKey || !searchUrl || popularVenuePaths.size === 0) {
        status.textContent = 'Карта временно недоступна.';
        return;
    }

    let yandexMap = null;
    let clusterer = null;
    let controller = null;
    let initialized = false;
    let renderVersion = 0;

    const observer = new IntersectionObserver((entries) => {
        if (!entries.some((entry) => entry.isIntersecting)) {
            return;
        }
        observer.disconnect();
        initialized = true;
        renderMap();
    }, { rootMargin: '180px 0px' });
    observer.observe(mapPreview);

    async function renderMap() {
        if (!initialized) {
            return;
        }

        const version = ++renderVersion;
        controller?.abort();
        controller = new AbortController();
        status.hidden = false;
        status.textContent = 'Загружаем популярные площадки…';

        try {
            const venues = await fetchPopularVenues(controller.signal);
            if (version !== renderVersion) {
                return;
            }

            const mapped = venues.filter((venue) => (
                Number.isFinite(Number(venue.latitude))
                && Number.isFinite(Number(venue.longitude))
            ));

            if (mapped.length === 0) {
                clearGeoObjects();
                status.textContent = 'У популярных площадок пока нет координат.';
                return;
            }

            await loadYandexMaps(apiKey);
            await new Promise((resolve) => window.ymaps.ready(resolve));
            if (version !== renderVersion) {
                return;
            }

            if (!yandexMap) {
                yandexMap = new window.ymaps.Map(canvas, {
                    center: [55.751244, 37.618423],
                    zoom: 10,
                    controls: ['zoomControl', 'fullscreenControl'],
                });
            }

            clearGeoObjects();
            clusterer = new window.ymaps.Clusterer({
                preset: 'islands#orangeClusterIcons',
                groupByCoordinates: false,
                clusterDisableClickZoom: false,
                clusterOpenBalloonOnClick: false,
                clusterHideIconOnBalloonOpen: false,
                geoObjectHideIconOnBalloonOpen: false,
                gridSize: 64,
            });

            const placemarks = mapped.map((venue) => new window.ymaps.Placemark(
                [Number(venue.latitude), Number(venue.longitude)],
                {
                    hintContent: venue.name,
                    balloonContentHeader: escapeHtml(venue.name || 'Площадка'),
                    balloonContentBody: buildBalloonBody(venue),
                },
                { preset: 'islands#orangeSportIcon' },
            ));

            clusterer.add(placemarks);
            yandexMap.geoObjects.add(clusterer);

            const bounds = clusterer.getBounds();
            if (bounds) {
                yandexMap.setBounds(bounds, {
                    checkZoomRange: true,
                    zoomMargin: 44,
                });
            }
            window.setTimeout(() => yandexMap?.container.fitToViewport(), 0);
            status.hidden = true;
        } catch (error) {
            if (error.name !== 'AbortError') {
                status.hidden = false;
                status.textContent = 'Не удалось загрузить популярные площадки на карте.';
            }
        }
    }

    async function fetchPopularVenues(signal) {
        const parameters = new URLSearchParams({
            query: '',
            confirmed_only: '1',
            operational_status: 'active',
            limit: '200',
        });

        const response = await fetch(`${searchUrl}?${parameters.toString()}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal,
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw new Error(payload.message || 'Не удалось загрузить площадки.');
        }

        const venues = Array.isArray(payload.venues) ? payload.venues : [];
        return venues.filter((venue) => popularVenuePaths.has(normalizeUrlPath(venue.url)));
    }

    function clearGeoObjects() {
        clusterer = null;
        yandexMap?.geoObjects.removeAll();
    }
}

function normalizeUrlPath(value) {
    if (!value) {
        return '';
    }

    try {
        const url = new URL(String(value), window.location.origin);
        return url.pathname.replace(/\/+$/, '') || '/';
    } catch {
        return String(value).split(/[?#]/, 1)[0].replace(/\/+$/, '');
    }
}

function buildBalloonBody(venue) {
    const address = displayAddress(venue.address);
    const access = venue.requires_payment === true
        ? 'Платно'
        : (venue.requires_payment === false ? 'Бесплатно' : 'Условия уточняются');
    const url = venue.url ? escapeHtml(venue.url) : '';

    return [
        address ? `<div>${escapeHtml(address)}</div>` : '',
        `<div>${escapeHtml(access)}</div>`,
        url ? `<div><a href="${url}">Открыть площадку</a></div>` : '',
    ].filter(Boolean).join('');
}

function displayAddress(address) {
    if (!address) {
        return '';
    }
    if (typeof address === 'string') {
        return address;
    }
    if (typeof address.display === 'string') {
        return address.display;
    }
    if (typeof address.display_address === 'string') {
        return address.display_address;
    }

    return [address.city, address.street, address.building]
        .filter(Boolean)
        .join(', ');
}

function escapeHtml(value) {
    const node = document.createElement('span');
    node.textContent = String(value ?? '');
    return node.innerHTML;
}
