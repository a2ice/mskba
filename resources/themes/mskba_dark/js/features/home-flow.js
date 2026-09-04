import $ from 'jquery';
import { loadYandexMaps } from '../core/yandex-maps.js';

function activatePanel(modal, section) {
    const requested = section === 'create' ? 'create' : 'search';
    modal.find('[data-home-flow-panel]').each(function () {
        this.hidden = this.dataset.homeFlowPanel !== requested;
    });
    modal.find('[data-home-flow-tab]').each(function () {
        $(this).toggleClass('is-active', this.dataset.homeFlowTab === requested);
    });
}

$(document).on('modal:opened', function (_event, modal) {
    const flow = modal.find('[data-home-flow]');
    if (!flow.length) {
        return;
    }

    activatePanel(modal, String(modal.data('modalInitialSection') || 'search'));
    modal.removeData('modalInitialSection');
});

$(document).on('click', '[data-home-flow-tab]', function () {
    const modal = $(this).closest('[data-modal]');
    if (!modal.length) {
        return;
    }

    activatePanel(modal, String(this.dataset.homeFlowTab || 'search'));
});

function prepareHomeVenueDiscovery(root) {
    root.dataset.homeVenueDiscovery = '';
    root.dataset.searchUrl ||= '/venues/search';

    const shell = root.querySelector('.home-venue-search-shell');
    const queryInput = shell?.querySelector('input[type="search"], input[type="text"]');
    if (queryInput) queryInput.dataset.homeVenueQuery = '';

    const filterRow = shell?.querySelector('.home-filter-row');
    if (filterRow && !filterRow.querySelector('[data-home-venue-metro]')) {
        const metroButton = [...filterRow.querySelectorAll('button')]
            .find((button) => button.querySelector('.ti-train'));
        const metroWrap = document.createElement('label');
        metroWrap.className = 'home-metro-filter';
        metroWrap.innerHTML = '<i class="ti ti-train" aria-hidden="true"></i><select data-home-venue-metro aria-label="Фильтр по метро"><option value="">Любое метро</option></select>';
        if (metroButton) metroButton.replaceWith(metroWrap);
        else filterRow.prepend(metroWrap);
    }

    const mapFocus = filterRow ? [...filterRow.querySelectorAll('button')]
        .find((button) => button.querySelector('.ti-map')) : null;
    if (mapFocus) mapFocus.dataset.homeVenueMapFocus = '';

    if (shell && !shell.querySelector('[data-home-venue-results]')) {
        const results = document.createElement('div');
        results.className = 'home-venue-predictive-results';
        results.dataset.homeVenueResults = '';
        results.hidden = true;
        shell.append(results);
    }

    const mapPreview = root.querySelector('.home-venue-map-preview');
    if (mapPreview && !mapPreview.querySelector('[data-home-venue-map]')) {
        mapPreview.replaceChildren();
        const status = document.createElement('p');
        status.className = 'home-venue-map-preview__status';
        status.dataset.homeVenueMapStatus = '';
        status.textContent = 'Загружаем площадки на карте…';
        const canvas = document.createElement('div');
        canvas.className = 'home-venue-map-preview__canvas';
        canvas.dataset.homeVenueMap = '';
        mapPreview.append(canvas, status);
    }

    return root;
}

function initHomeVenueDiscovery(rawRoot) {
    const root = prepareHomeVenueDiscovery(rawRoot);
    const searchUrl = root.dataset.searchUrl || '/venues/search';
    const apiKey = root.dataset.yandexMapApiKey || document.body?.dataset.yandexMapApiKey || '';
    const queryInput = root.querySelector('[data-home-venue-query]');
    const metroSelect = root.querySelector('[data-home-venue-metro]');
    const results = root.querySelector('[data-home-venue-results]');
    const mapElement = root.querySelector('[data-home-venue-map]');
    const mapStatus = root.querySelector('[data-home-venue-map-status]');
    const mapFocus = root.querySelector('[data-home-venue-map-focus]');
    let timer = null;
    let controller = null;
    let map = null;
    let mapReady = null;
    let initialVenues = [];

    if (!(queryInput instanceof HTMLInputElement) || !(results instanceof HTMLElement)) return;

    const fetchVenues = async (query = '', metroId = '', limit = 30, signal = null) => {
        const url = new URL(searchUrl, window.location.origin);
        if (query) url.searchParams.set('query', query);
        if (metroId) url.searchParams.set('metro_station_id', metroId);
        url.searchParams.set('confirmed_only', '1');
        url.searchParams.set('operational_status', 'active');
        url.searchParams.set('limit', String(limit));

        const response = await fetch(url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal,
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(payload.message || 'Не удалось загрузить площадки.');
        return Array.isArray(payload.venues) ? payload.venues : [];
    };

    const renderResults = (venues, showEmpty = true) => {
        results.replaceChildren();
        if (!venues.length) {
            if (showEmpty) {
                const empty = document.createElement('p');
                empty.className = 'home-venue-predictive-results__empty';
                empty.textContent = 'Подходящих площадок не найдено.';
                results.append(empty);
                results.hidden = false;
            } else {
                results.hidden = true;
            }
            return;
        }

        venues.slice(0, 8).forEach((venue) => {
            const item = document.createElement('a');
            item.className = 'home-venue-predictive-result';
            item.href = venue.url || '#';
            const copy = document.createElement('span');
            const title = document.createElement('strong');
            const meta = document.createElement('small');
            title.textContent = venue.name || 'Площадка';
            meta.textContent = venue.address || venue.raw_address || 'Адрес уточняется';
            copy.append(title, meta);
            const arrow = document.createElement('i');
            arrow.className = 'ti ti-arrow-up-right';
            item.append(copy, arrow);
            results.append(item);
        });
        results.hidden = false;
    };

    const fillMetroOptions = (venues) => {
        if (!(metroSelect instanceof HTMLSelectElement)) return;
        const stations = new Map();
        venues.forEach((venue) => {
            (Array.isArray(venue.metro_stations) ? venue.metro_stations : []).forEach((station) => {
                const id = station.id ?? station.metro_station_id;
                const name = station.name ?? station.label;
                if (id && name && !stations.has(String(id))) stations.set(String(id), String(name));
            });
        });
        [...stations.entries()]
            .sort((a, b) => a[1].localeCompare(b[1], 'ru'))
            .forEach(([id, name]) => {
                const option = document.createElement('option');
                option.value = id;
                option.textContent = name;
                metroSelect.append(option);
            });
    };

    const ensureMap = async () => {
        if (!mapElement || !apiKey) {
            if (mapStatus) mapStatus.textContent = apiKey ? 'Карта недоступна.' : 'Ключ Яндекс Карт не настроен.';
            return null;
        }
        if (map) return map;
        if (mapReady) return mapReady;

        mapReady = loadYandexMaps(apiKey)
            .then(() => new Promise((resolve) => window.ymaps.ready(resolve)))
            .then(() => {
                map = new window.ymaps.Map(mapElement, {
                    center: [55.751244, 37.618423],
                    zoom: 10,
                    controls: ['zoomControl', 'fullscreenControl', 'geolocationControl'],
                });
                if (mapStatus) mapStatus.hidden = true;
                return map;
            })
            .catch(() => {
                if (mapStatus) mapStatus.textContent = 'Не удалось загрузить карту.';
                return null;
            });
        return mapReady;
    };

    const updateMap = async (venues) => {
        const activeMap = await ensureMap();
        if (!activeMap) return;
        activeMap.geoObjects.removeAll();
        const coordinates = [];

        venues.forEach((venue) => {
            const latitude = Number(venue.latitude);
            const longitude = Number(venue.longitude);
            if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) return;
            coordinates.push([latitude, longitude]);
            activeMap.geoObjects.add(new window.ymaps.Placemark(
                [latitude, longitude],
                {
                    hintContent: venue.name,
                    balloonContentHeader: venue.name,
                    balloonContentBody: `${escapeHtml(venue.address || venue.raw_address || '')}<br><a href="${escapeHtml(venue.url || '#')}">Открыть площадку</a>`,
                },
                { preset: 'islands#orangeSportIcon' },
            ));
        });

        if (coordinates.length > 1) {
            activeMap.setBounds(window.ymaps.util.bounds.fromPoints(coordinates), { checkZoomRange: true, zoomMargin: 32 });
        } else if (coordinates.length === 1) {
            activeMap.setCenter(coordinates[0], 14);
        }
        window.setTimeout(() => activeMap.container.fitToViewport(), 0);
    };

    const runSearch = async () => {
        controller?.abort();
        const activeController = new AbortController();
        controller = activeController;
        const query = queryInput.value.trim();
        const metroId = metroSelect instanceof HTMLSelectElement ? metroSelect.value : '';

        if (query.length < 2 && !metroId) {
            renderResults([], false);
            updateMap(initialVenues);
            return;
        }

        root.classList.add('is-loading');
        try {
            const venues = await fetchVenues(query, metroId, 30, activeController.signal);
            renderResults(venues);
            updateMap(venues);
        } catch (error) {
            if (error.name !== 'AbortError') renderResults([]);
        } finally {
            if (controller === activeController) {
                controller = null;
                root.classList.remove('is-loading');
            }
        }
    };

    queryInput.addEventListener('input', () => {
        window.clearTimeout(timer);
        timer = window.setTimeout(runSearch, 260);
    });
    metroSelect?.addEventListener('change', runSearch);
    mapFocus?.addEventListener('click', () => {
        mapElement?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        window.setTimeout(async () => (await ensureMap())?.container.fitToViewport(), 420);
    });
    document.addEventListener('click', (event) => {
        if (!root.contains(event.target)) results.hidden = true;
    });
    queryInput.addEventListener('focus', () => {
        if (results.childElementCount > 0) results.hidden = false;
    });

    fetchVenues('', '', 200)
        .then((venues) => {
            initialVenues = venues;
            fillMetroOptions(venues);
            updateMap(venues);
        })
        .catch(() => {
            if (mapStatus) mapStatus.textContent = 'Не удалось загрузить площадки.';
        });
}

function escapeHtml(value) {
    const node = document.createElement('span');
    node.textContent = String(value ?? '');
    return node.innerHTML;
}

const venueDiscoveryRoots = document.querySelectorAll('[data-home-venue-discovery], .home-split--venues');
[...new Set(venueDiscoveryRoots)].forEach(initHomeVenueDiscovery);
