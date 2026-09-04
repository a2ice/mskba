import { loadYandexMaps } from '../core/yandex-maps.js';
import '../../css/venue-selector-metro.css';

const selectorStates = new WeakMap();

document.querySelectorAll('[data-venue-selector]').forEach(initVenueMetroFilter);

function initVenueMetroFilter(container) {
    if (!container || selectorStates.has(container)) {
        return;
    }

    const metroSelect = container.querySelector('[data-venue-selector-metro-filter]');
    const metroToggle = container.querySelector('[data-venue-selector-metro-toggle]');
    const metroPanel = container.querySelector('[data-venue-selector-metro-panel]');
    const input = container.querySelector('[data-venue-selector-input]');
    const valueInput = container.querySelector('[data-venue-selector-value]');
    const clearButton = container.querySelector('[data-venue-selector-clear]');
    const list = container.querySelector('[data-venue-selector-list]');
    const message = container.querySelector('[data-venue-selector-message]');
    const mapOpenOriginal = container.querySelector('[data-venue-map-selector-open]');
    const mapModal = document.querySelector(`[data-modal="${container.dataset.mapModal}"]`);
    const mapElement = mapModal?.querySelector('[data-venue-selector-map]');
    const mapMessage = mapModal?.querySelector('[data-venue-selector-map-message]');
    const mapFallback = mapModal?.querySelector('[data-venue-selector-map-fallback]');

    if (!metroSelect || !metroToggle || !metroPanel || !input || !valueInput || !list || !message) {
        return;
    }

    let searchTimer = null;
    let searchController = null;
    let yandexMap = null;
    let renderVersion = 0;
    let suppressObserver = false;

    const mapOpen = replaceMapOpen(mapOpenOriginal);

    metroToggle.addEventListener('click', () => {
        const willOpen = metroPanel.hidden;
        metroPanel.hidden = !willOpen;
        metroToggle.classList.toggle('is-active', willOpen || selectedMetroIds().length > 0);
        metroToggle.setAttribute('aria-expanded', String(willOpen));

        if (willOpen) {
            window.setTimeout(() => {
                metroSelect.tomselect?.focus();
            }, 0);
        }
    });

    metroSelect.addEventListener('change', () => {
        updateMetroToggle();
        clearSelectedVenue();
        hideMessage();
        hideList();

        window.clearTimeout(searchTimer);
        searchController?.abort();
        searchController = null;

        const metroIds = selectedMetroIds();
        if (metroIds.length === 0) {
            input.dispatchEvent(new Event('input', { bubbles: true }));
            return;
        }

        searchTimer = window.setTimeout(() => searchWithMetros(input.value.trim()), 80);
    });

    input.addEventListener('input', (event) => {
        if (selectedMetroIds().length === 0) {
            return;
        }

        event.stopImmediatePropagation();
        clearSelectedVenue();
        hideMessage();
        hideList();

        window.clearTimeout(searchTimer);
        searchController?.abort();
        searchController = null;

        searchTimer = window.setTimeout(() => searchWithMetros(input.value.trim()), 300);
    }, { capture: true });

    mapOpen?.addEventListener('click', () => {
        window.setTimeout(loadFilteredMap, 0);
    });

    const listObserver = new MutationObserver(() => {
        if (suppressObserver || selectedMetroIds().length === 0) {
            return;
        }
        filterRenderedOptionsToSelectedMetros();
    });
    listObserver.observe(list, { childList: true });

    updateMetroToggle();
    selectorStates.set(container, { metroSelect });

    function selectedMetroIds() {
        return Array.from(metroSelect.selectedOptions)
            .map((option) => Number(option.value))
            .filter((value) => Number.isInteger(value) && value > 0);
    }

    function selectedMetroNames() {
        return Array.from(metroSelect.selectedOptions)
            .map((option) => String(option.textContent || '').replace(/\s*\([^)]*\)\s*$/, '').trim())
            .filter(Boolean);
    }

    function updateMetroToggle() {
        const count = selectedMetroIds().length;
        metroToggle.textContent = count > 0 ? `Метро · ${count}` : 'Выбор метро';
        metroToggle.classList.toggle('is-active', count > 0 || !metroPanel.hidden);
    }

    function clearSelectedVenue() {
        if (!valueInput.value) {
            return;
        }

        valueInput.value = '';
        const previewOpen = container.querySelector('[data-venue-preview-open]');
        if (previewOpen) {
            previewOpen.dataset.previewUrl = '';
            previewOpen.hidden = true;
        }
        const scope = container.querySelector('[data-venue-booking-scope]');
        const scopeInput = container.querySelector('[data-venue-booking-scope-input]');
        if (scope) {
            scope.hidden = true;
        }
        if (scopeInput) {
            scopeInput.value = 'whole';
        }
        valueInput.dispatchEvent(new Event('change', { bubbles: true }));
    }

    async function searchWithMetros(query) {
        const version = ++renderVersion;
        searchController?.abort();
        const controller = new AbortController();
        searchController = controller;
        setLoading(true);

        try {
            const venues = await fetchMergedVenues(query, 30, controller.signal);
            if (version !== renderVersion) {
                return;
            }
            renderOptions(venues);
            if (venues.length === 0) {
                showMessage('Варианты не найдены.');
            } else {
                hideMessage();
            }
        } catch (error) {
            if (error.name !== 'AbortError') {
                showMessage(error.message || 'Не удалось загрузить площадки.');
            }
        } finally {
            if (searchController === controller) {
                searchController = null;
                setLoading(false);
            }
        }
    }

    async function fetchMergedVenues(query = '', limit = 30, signal = null) {
        const metroIds = selectedMetroIds();
        const requests = metroIds.length > 0
            ? metroIds.map((metroId) => fetchVenues(query, limit, metroId, signal))
            : [fetchVenues(query, limit, null, signal)];
        const groups = await Promise.all(requests);
        const unique = new Map();

        groups.flat().forEach((venue) => {
            unique.set(Number(venue.id), venue);
        });

        return Array.from(unique.values())
            .sort((left, right) => String(left.name || '').localeCompare(String(right.name || ''), 'ru'))
            .slice(0, limit);
    }

    async function fetchVenues(query, limit, metroStationId, signal) {
        const parameters = new URLSearchParams({
            query,
            confirmed_only: container.dataset.confirmedOnly || '0',
            limit: String(limit),
        });

        if (container.dataset.operationalStatus) {
            parameters.set('operational_status', container.dataset.operationalStatus);
        }
        if (metroStationId) {
            parameters.set('metro_station_id', String(metroStationId));
        }

        const response = await fetch(`${container.dataset.searchUrl}?${parameters.toString()}`, {
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

    function renderOptions(venues) {
        suppressObserver = true;
        list.dataset.venues = JSON.stringify(venues);
        list.replaceChildren();

        venues.forEach((venue, index) => {
            const option = document.createElement('button');
            const title = document.createElement('strong');
            const address = document.createElement('span');

            option.type = 'button';
            option.className = 'address-suggest__item predictive-search__item venue-selector-option';
            option.dataset.venueSelectorOption = String(index);
            option.setAttribute('role', 'option');

            title.className = 'venue-selector-option__name';
            title.textContent = venue.name || 'Площадка';
            option.append(title);

            if (venue.address) {
                address.className = 'address-suggest__metro predictive-search__meta venue-selector-option__address';
                address.textContent = displayAddress(venue.address);
                option.append(address);
            }

            list.append(option);
        });

        list.classList.toggle('d-none', venues.length === 0);
        suppressObserver = false;
    }

    function filterRenderedOptionsToSelectedMetros() {
        const names = selectedMetroNames();
        if (names.length === 0) {
            return;
        }

        const venues = JSON.parse(list.dataset.venues || '[]');
        const filtered = venues.filter((venue) => {
            const venueMetros = Array.isArray(venue.metro_stations) ? venue.metro_stations : [];
            return venueMetros.some((metro) => names.includes(String(metro || '').trim()));
        });

        if (filtered.length !== venues.length) {
            renderOptions(filtered);
        }
    }

    function replaceMapOpen(original) {
        if (!original) {
            return null;
        }

        const clone = original.cloneNode(true);
        original.replaceWith(clone);
        return clone;
    }

    async function loadFilteredMap() {
        if (!mapElement || !mapMessage) {
            return;
        }

        mapMessage.textContent = 'Загружаем площадки…';
        mapMessage.hidden = false;

        try {
            const venues = await fetchMergedVenues('', 200, null);
            const mapped = venues.filter((venue) => Number.isFinite(Number(venue.latitude)) && Number.isFinite(Number(venue.longitude)));
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
            yandexMap?.destroy();
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
        suppressObserver = true;
        const option = document.createElement('button');
        option.type = 'button';
        option.dataset.venueSelectorOption = '0';
        list.dataset.venues = JSON.stringify([venue]);
        list.append(option);
        option.click();
        option.remove();
        suppressObserver = false;
        mapModal?.querySelector('[data-modal-action="close"]')?.click();
    }

    function setLoading(loading) {
        if (!clearButton) {
            return;
        }
        clearButton.disabled = loading;
        clearButton.classList.toggle('is-loading', loading);
        clearButton.hidden = !loading && !input.value;
    }

    function hideList() {
        list.classList.add('d-none');
    }

    function showMessage(text) {
        message.textContent = text;
        message.classList.remove('d-none');
    }

    function hideMessage() {
        message.textContent = '';
        message.classList.add('d-none');
    }
}

function displayAddress(address) {
    return String(address || '')
        .replace(/^(?:Россия|Российская Федерация)\s*,\s*/iu, '')
        .trim();
}
