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
    const input = selector.querySelector('[data-venue-selector-input]');
    const valueInput = selector.querySelector('[data-venue-selector-value]');
    const metroSelect = selector.querySelector('[data-venue-selector-metro-filter]');
    const mapSource = source.querySelector('[data-venue-selector-map]');
    const apiKey = mapSource?.dataset.yandexMapApiKey || '';
    const searchUrl = selector.dataset.searchUrl || '';

    mapPreview.classList.add('home-venue-map-preview--live');
    mapPreview.setAttribute('aria-label', 'Интерактивная карта площадок');
    mapPreview.replaceChildren();

    const canvas = document.createElement('div');
    canvas.className = 'home-venue-map-preview__canvas';

    const status = document.createElement('p');
    status.className = 'home-venue-map-preview__status';
    status.textContent = 'Загружаем площадки…';

    const caption = document.createElement('div');
    caption.className = 'home-venue-map-preview__caption home-venue-map-preview__caption--live';
    caption.innerHTML = '<i class="ti ti-map-2"></i><strong>Карта площадок</strong><small>Поиск и метро сразу обновляют точки на карте</small>';

    mapPreview.append(canvas, status, caption);

    if (!input || !metroSelect || !apiKey || !searchUrl) {
        status.textContent = 'Карта временно недоступна.';
        return;
    }

    let yandexMap = null;
    let clusterer = null;
    let controller = null;
    let searchTimer = null;
    let initialized = false;
    let dirty = true;
    let renderVersion = 0;
    let lastVenues = [];
    const placemarksByVenue = new Map();

    const requestRender = () => {
        dirty = true;
        if (!initialized) {
            return;
        }
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(renderMap, 280);
    };

    input.addEventListener('input', requestRender);
    metroSelect.addEventListener('change', requestRender);
    valueInput?.addEventListener('change', () => {
        const selectedId = Number(valueInput.value);
        if (!selectedId || !initialized) {
            return;
        }

        const placemark = placemarksByVenue.get(selectedId);
        const venue = lastVenues.find((item) => Number(item.id) === selectedId);
        if (!placemark || !venue) {
            requestRender();
            return;
        }

        const coordinates = [Number(venue.latitude), Number(venue.longitude)];
        yandexMap?.setCenter(coordinates, Math.max(yandexMap.getZoom(), 14), { duration: 250 });
        window.setTimeout(() => placemark.balloon.open(), 260);
    });

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
        if (!dirty) {
            return;
        }

        dirty = false;
        const version = ++renderVersion;
        controller?.abort();
        controller = new AbortController();
        status.hidden = false;
        status.textContent = 'Загружаем площадки…';

        try {
            const venues = await fetchVenues(controller.signal);
            if (version !== renderVersion) {
                return;
            }

            const mapped = venues.filter((venue) => (
                Number.isFinite(Number(venue.latitude))
                && Number.isFinite(Number(venue.longitude))
            ));
            lastVenues = mapped;

            if (mapped.length === 0) {
                clearGeoObjects();
                status.textContent = 'По выбранным условиям площадки не найдены.';
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

            const placemarks = mapped.map((venue) => {
                const placemark = new window.ymaps.Placemark(
                    [Number(venue.latitude), Number(venue.longitude)],
                    {
                        hintContent: venue.name,
                        balloonContentHeader: escapeHtml(venue.name || 'Площадка'),
                        balloonContentBody: buildBalloonBody(venue),
                    },
                    { preset: 'islands#orangeSportIcon' },
                );
                placemarksByVenue.set(Number(venue.id), placemark);
                return placemark;
            });

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
                dirty = true;
                status.hidden = false;
                status.textContent = 'Не удалось загрузить площадки на карте.';
            }
        }
    }

    async function fetchVenues(signal) {
        const metroIds = Array.from(metroSelect.selectedOptions)
            .map((option) => Number(option.value))
            .filter((value) => Number.isInteger(value) && value > 0);
        const query = valueInput?.value ? '' : input.value.trim();
        const requests = metroIds.length > 0
            ? metroIds.map((metroId) => fetchVenueGroup(query, metroId, signal))
            : [fetchVenueGroup(query, null, signal)];
        const groups = await Promise.all(requests);
        const unique = new Map();

        groups.flat().forEach((venue) => unique.set(Number(venue.id), venue));
        return Array.from(unique.values());
    }

    async function fetchVenueGroup(query, metroStationId, signal) {
        const parameters = new URLSearchParams({
            query,
            confirmed_only: '1',
            operational_status: 'active',
            limit: '200',
        });

        if (metroStationId) {
            parameters.set('metro_station_id', String(metroStationId));
        }

        const venueType = String(selector.dataset.venueTypeFilter || '').trim();
        if (venueType && venueType !== 'any') {
            parameters.set('type', venueType);
        }

        const payment = String(selector.dataset.requiresPaymentFilter || '').trim();
        if (payment === '0' || payment === '1') {
            parameters.set('requires_payment', payment);
        }

        const response = await fetch(`${searchUrl}?${parameters.toString()}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal,
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw new Error(payload.message || 'Не удалось загрузить площадки.');
        }

        return Array.isArray(payload.venues) ? payload.venues : [];
    }

    function clearGeoObjects() {
        placemarksByVenue.clear();
        clusterer = null;
        yandexMap?.geoObjects.removeAll();
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
