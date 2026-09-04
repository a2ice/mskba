import { loadYandexMaps } from '../core/yandex-maps.js';

const initialized = new WeakSet();

document.querySelectorAll('[data-venue-selector]').forEach(initMetroAwareMap);

function initMetroAwareMap(container) {
    if (!container || initialized.has(container)) {
        return;
    }

    const metroSelect = container.querySelector('[data-venue-selector-metro-filter]');
    const mapOpenOriginal = container.querySelector('[data-venue-map-selector-open]');
    const list = container.querySelector('[data-venue-selector-list]');
    const mapModal = document.querySelector(`[data-modal="${container.dataset.mapModal}"]`);
    let mapElement = mapModal?.querySelector('[data-venue-selector-map]');
    const mapMessage = mapModal?.querySelector('[data-venue-selector-map-message]');
    const mapFallback = mapModal?.querySelector('[data-venue-selector-map-fallback]');

    if (!metroSelect || !mapOpenOriginal || !list || !mapModal || !mapElement || !mapMessage) {
        return;
    }

    // Both the base selector and the metro enhancer attach map loaders directly
    // to this link. Replacing it here leaves the modal trigger attributes intact,
    // but makes this metro-aware loader the single owner of visible map points.
    const mapOpen = mapOpenOriginal.cloneNode(true);
    mapOpenOriginal.replaceWith(mapOpen);

    let yandexMap = null;
    let renderVersion = 0;

    mapOpen.addEventListener('click', () => {
        window.setTimeout(loadFilteredMap, 0);
    });

    initialized.add(container);

    function selectedMetroIds() {
        return Array.from(metroSelect.selectedOptions)
            .map((option) => Number(option.value))
            .filter((value) => Number.isInteger(value) && value > 0);
    }

    function selectedMetroNames() {
        return Array.from(metroSelect.selectedOptions)
            .map((option) => {
                try {
                    return normalizeMetroName(JSON.parse(option.dataset.data || '{}').text || option.textContent);
                } catch (_) {
                    return normalizeMetroName(option.textContent);
                }
            })
            .filter(Boolean);
    }

    async function loadFilteredMap() {
        const version = ++renderVersion;

        mapMessage.textContent = 'Загружаем площадки…';
        mapMessage.hidden = false;
        renderMapFallback([]);

        if (yandexMap) {
            yandexMap.destroy();
            yandexMap = null;
        }

        // Recreate the canvas before every load. This also detaches any stale
        // unfiltered Yandex map that could have been initialized by an older
        // listener and guarantees that only the current filter result is shown.
        const freshMapElement = mapElement.cloneNode(false);
        mapElement.replaceWith(freshMapElement);
        mapElement = freshMapElement;

        try {
            const venues = await fetchMergedVenues();
            if (version !== renderVersion) {
                return;
            }

            const mapped = venues.filter((venue) => (
                Number.isFinite(Number(venue.latitude))
                && Number.isFinite(Number(venue.longitude))
            ));
            const unmapped = venues.filter((venue) => !mapped.includes(venue));
            renderMapFallback(unmapped);

            if (venues.length === 0) {
                mapMessage.textContent = 'Подходящие площадки не найдены.';
                return;
            }

            if (mapped.length === 0) {
                mapMessage.textContent = 'У подходящих площадок пока нет координат. Выберите площадку из списка.';
                return;
            }

            const apiKey = mapElement.dataset.yandexMapApiKey;
            if (!apiKey) {
                mapMessage.textContent = 'Ключ Яндекс Карт не настроен.';
                return;
            }

            await loadYandexMaps(apiKey);
            await new Promise((resolve) => window.ymaps.ready(resolve));
            if (version !== renderVersion) {
                return;
            }

            yandexMap = new window.ymaps.Map(mapElement, {
                center: [55.751244, 37.618423],
                zoom: 10,
                controls: ['zoomControl', 'fullscreenControl'],
            });

            mapped.forEach((venue) => {
                const placemark = new window.ymaps.Placemark(
                    [Number(venue.latitude), Number(venue.longitude)],
                    {
                        hintContent: venue.name,
                        balloonContentHeader: venue.name,
                        balloonContentBody: displayAddress(venue.address),
                    },
                    { preset: 'islands#orangeSportIcon' },
                );

                placemark.events.add('click', () => selectVenueThroughCore(venue));
                yandexMap.geoObjects.add(placemark);
            });

            const bounds = yandexMap.geoObjects.getBounds();
            if (bounds) {
                yandexMap.setBounds(bounds, { checkZoomRange: true, zoomMargin: 40 });
            }

            mapMessage.hidden = unmapped.length === 0;
            if (unmapped.length > 0) {
                mapMessage.textContent = 'Площадки без координат можно выбрать из списка ниже.';
            }
        } catch (error) {
            mapMessage.textContent = error.message || 'Не удалось загрузить карту площадок.';
        }
    }

    async function fetchMergedVenues() {
        const metroIds = selectedMetroIds();
        const metroNames = selectedMetroNames();
        const requests = metroIds.length > 0
            ? metroIds.map((metroId) => fetchVenues(metroId))
            : [fetchVenues(null)];
        const groups = await Promise.all(requests);
        const unique = new Map();

        groups.flat().forEach((venue) => {
            unique.set(Number(venue.id), venue);
        });

        let venues = Array.from(unique.values());

        // Some selector contexts (notably the event-creation wizard) use a
        // specialized availability endpoint. It returns metro metadata, but may
        // not apply metro_station_id itself. Keep the shared component correct in
        // every context by enforcing the same OR rule on the returned payload.
        if (metroNames.length > 0) {
            venues = venues.filter((venue) => venueMatchesMetro(venue, metroNames));
        }

        return venues
            .sort((left, right) => String(left.name || '').localeCompare(String(right.name || ''), 'ru'))
            .slice(0, 200);
    }

    async function fetchVenues(metroStationId) {
        const parameters = new URLSearchParams({
            query: '',
            confirmed_only: container.dataset.confirmedOnly || '0',
            limit: '200',
        });

        if (container.dataset.operationalStatus) {
            parameters.set('operational_status', container.dataset.operationalStatus);
        }
        if (metroStationId) {
            parameters.set('metro_station_id', String(metroStationId));
        }

        const venueType = String(container.dataset.venueTypeFilter || '').trim();
        if (venueType && venueType !== 'any') {
            parameters.set('type', venueType);
        }

        const payment = String(container.dataset.requiresPaymentFilter || '').trim();
        if (payment === '0' || payment === '1') {
            parameters.set('requires_payment', payment);
        }

        const response = await fetch(`${container.dataset.searchUrl}?${parameters.toString()}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        const payload = await response.json().catch(() => ({}));

        if (!response.ok) {
            throw new Error(payload.message || 'Не удалось загрузить площадки.');
        }

        return Array.isArray(payload.venues) ? payload.venues : [];
    }

    function renderMapFallback(venues) {
        if (!mapFallback) {
            return;
        }

        mapFallback.replaceChildren();
        mapFallback.hidden = venues.length === 0;

        venues.forEach((venue) => {
            const button = document.createElement('button');
            const title = document.createElement('strong');
            const address = document.createElement('span');

            button.type = 'button';
            button.className = 'venue-selector-map-fallback__item';
            title.textContent = venue.name || 'Площадка';
            address.textContent = displayAddress(venue.address);
            button.append(title, address);
            button.addEventListener('click', () => selectVenueThroughCore(venue));
            mapFallback.append(button);
        });
    }

    function selectVenueThroughCore(venue) {
        const option = document.createElement('button');
        option.type = 'button';
        option.dataset.venueSelectorOption = '0';
        list.dataset.venues = JSON.stringify([venue]);
        list.append(option);
        option.click();
        option.remove();
        mapModal.querySelector('[data-modal-action="close"]')?.click();
    }
}

function venueMatchesMetro(venue, selectedNames) {
    const venueMetros = Array.isArray(venue.metro_stations) ? venue.metro_stations : [];
    return venueMetros.some((metro) => selectedNames.includes(normalizeMetroName(metro)));
}

function normalizeMetroName(value) {
    return String(value || '')
        .replace(/\s*\([^()]*\)\s*$/, '')
        .trim();
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
