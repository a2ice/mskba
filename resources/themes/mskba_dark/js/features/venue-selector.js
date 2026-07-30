import { loadYandexMaps } from '../core/yandex-maps.js';

document.querySelectorAll('[data-venue-selector]').forEach(initVenueSelector);

function initVenueSelector(container) {
    const input = container.querySelector('[data-venue-selector-input]');
    const valueInput = container.querySelector('[data-venue-selector-value]');
    const clearButton = container.querySelector('[data-venue-selector-clear]');
    const list = container.querySelector('[data-venue-selector-list]');
    const message = container.querySelector('[data-venue-selector-message]');
    const previewOpen = container.querySelector('[data-venue-preview-open]');
    const startInput = container.dataset.startInput
        ? document.querySelector(container.dataset.startInput)
        : null;
    const durationInput = container.dataset.durationInput
        ? document.querySelector(container.dataset.durationInput)
        : null;
    const mapOpen = container.querySelector('[data-venue-map-selector-open]');
    const mapModal = document.querySelector(`[data-modal="${container.dataset.mapModal}"]`);
    const mapElement = mapModal?.querySelector('[data-venue-selector-map]');
    const mapMessage = mapModal?.querySelector('[data-venue-selector-map-message]');
    const previewModal = document.querySelector(`[data-modal="${container.dataset.previewModal}"]`);
    let searchTimer = null;
    let searchController = null;
    let availabilityController = null;
    let previewController = null;
    let yandexMap = null;

    if (!input || !valueInput || !clearButton || !list || !message) {
        return;
    }

    input.setCustomValidity(valueInput.value ? '' : 'Выберите площадку из списка.');

    input.addEventListener('input', () => {
        availabilityController?.abort();
        availabilityController = null;
        clearSelectedVenue();
        hideMessage();
        hideList();
        updateControl();

        window.clearTimeout(searchTimer);
        searchController?.abort();
        searchController = null;

        const query = input.value.trim();
        if (!query) {
            input.setCustomValidity('Выберите площадку из списка.');
            return;
        }

        input.setCustomValidity('Выберите площадку из списка.');
        setControlState('loading');
        searchTimer = window.setTimeout(() => search(query), 300);
    });

    input.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown') {
            const firstOption = list.querySelector('[data-venue-selector-option]');
            if (firstOption) {
                event.preventDefault();
                firstOption.focus();
            }
        }

        if (event.key === 'Escape') {
            hideList();
        }
    });

    clearButton.addEventListener('click', () => {
        searchController?.abort();
        searchController = null;
        availabilityController?.abort();
        availabilityController = null;
        window.clearTimeout(searchTimer);
        input.value = '';
        clearSelectedVenue();
        hideList();
        hideMessage();
        updateControl();
        input.setCustomValidity('Выберите площадку из списка.');
        input.focus();
    });

    list.addEventListener('click', (event) => {
        const option = event.target.closest('[data-venue-selector-option]');
        if (!option) {
            return;
        }

        const venues = JSON.parse(list.dataset.venues || '[]');
        const venue = venues[Number(option.dataset.venueSelectorOption)];
        if (venue) {
            selectVenue(venue);
        }
    });

    list.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            hideList();
            input.focus();
        }
    });

    document.addEventListener('click', (event) => {
        if (!container.contains(event.target)) {
            hideList();
        }
    });

    [startInput, durationInput].filter(Boolean).forEach((field) => {
        field.addEventListener('change', () => {
            hideList();
            revalidateSelectedVenue();
        });
    });

    mapOpen?.addEventListener('click', () => {
        window.setTimeout(loadMap, 0);
    });

    previewOpen?.addEventListener('click', () => {
        window.setTimeout(loadPreview, 0);
    });

    async function search(query) {
        searchController?.abort();
        const controller = new AbortController();
        searchController = controller;
        setControlState('loading');

        try {
            const venues = await fetchVenues(query, 30, controller.signal);
            renderOptions(venues);

            if (venues.length === 0) {
                showMessage('Варианты не найдены.');
            }
        } catch (error) {
            if (error.name !== 'AbortError') {
                showMessage(error.message || 'Не удалось загрузить площадки.');
            }
        } finally {
            if (searchController === controller) {
                searchController = null;
                updateControl();
            }
        }
    }

    async function fetchVenues(query = '', limit = 30, signal = null, venueId = null) {
        const parameters = buildParameters(query, limit, venueId);
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

    function buildParameters(query, limit, venueId = null) {
        const parameters = new URLSearchParams({
            query,
            confirmed_only: container.dataset.confirmedOnly || '0',
            limit: String(limit),
        });

        if (venueId) {
            parameters.set('venue_id', String(venueId));
        }

        if (container.dataset.operationalStatus) {
            parameters.set('operational_status', container.dataset.operationalStatus);
        }

        if (startInput?.value && durationInput?.value) {
            parameters.set('starts_at', startInput.value);
            parameters.set('duration_minutes', durationInput.value);
        }

        return parameters;
    }

    async function revalidateSelectedVenue() {
        availabilityController?.abort();
        availabilityController = null;

        const venueId = Number(valueInput.value);
        if (!venueId) {
            input.setCustomValidity('Выберите площадку из списка.');
            hideMessage();
            updateControl();
            return;
        }

        if (!startInput?.value || !durationInput?.value) {
            input.setCustomValidity('');
            hideMessage();
            updateControl();
            return;
        }

        const controller = new AbortController();
        availabilityController = controller;
        input.setCustomValidity('Проверяем доступность площадки.');
        setControlState('loading');

        try {
            const venues = await fetchVenues('', 1, controller.signal, venueId);
            const isAvailable = venues.some((venue) => Number(venue.id) === venueId);

            if (isAvailable) {
                input.setCustomValidity('');
                hideMessage();
            } else {
                input.setCustomValidity('Площадка недоступна в выбранное время.');
                showMessage('Площадка недоступна в выбранное время. Измените время или выберите другую площадку.');
            }
        } catch (error) {
            if (error.name !== 'AbortError') {
                input.setCustomValidity('Не удалось проверить доступность площадки.');
                showMessage(error.message || 'Не удалось проверить доступность площадки.');
            }
        } finally {
            if (availabilityController === controller) {
                availabilityController = null;
                updateControl();
            }
        }
    }

    function renderOptions(venues) {
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
            title.textContent = venue.name;
            option.append(title);

            if (venue.address) {
                address.className = 'address-suggest__metro predictive-search__meta venue-selector-option__address';
                address.textContent = displayAddress(venue.address);
                option.append(address);
            }

            list.append(option);
        });

        list.classList.toggle('d-none', venues.length === 0);
    }

    function selectVenue(venue) {
        availabilityController?.abort();
        availabilityController = null;
        const address = displayAddress(venue.address);
        input.value = `${venue.name}${address ? ` — ${address}` : ''}`;
        valueInput.value = String(venue.id);
        input.setCustomValidity('');
        hideList();
        hideMessage();
        updateControl();

        if (previewOpen) {
            previewOpen.dataset.previewUrl = venue.preview_url || '';
            previewOpen.hidden = !venue.preview_url;
        }

        valueInput.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function clearSelectedVenue() {
        valueInput.value = '';
        if (previewOpen) {
            previewOpen.dataset.previewUrl = '';
            previewOpen.hidden = true;
        }
    }

    function updateControl() {
        setControlState(input.value ? 'clear' : 'hidden');
    }

    function setControlState(state) {
        clearButton.hidden = state === 'hidden';
        clearButton.disabled = state === 'loading';
        clearButton.classList.toggle('is-loading', state === 'loading');
        clearButton.setAttribute(
            'aria-label',
            state === 'loading' ? 'Загрузка площадок' : 'Очистить площадку',
        );
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

    async function loadMap() {
        if (!mapElement || !mapMessage) {
            return;
        }

        mapMessage.textContent = 'Загружаем площадки…';
        mapMessage.hidden = false;

        try {
            const venues = (await fetchVenues('', 200)).filter(
                (venue) => Number.isFinite(Number(venue.latitude)) && Number.isFinite(Number(venue.longitude)),
            );

            if (!venues.length) {
                mapMessage.textContent = 'Подходящие площадки с координатами не найдены.';
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

            venues.forEach((venue) => {
                const placemark = new window.ymaps.Placemark(
                    [Number(venue.latitude), Number(venue.longitude)],
                    {
                        hintContent: venue.name,
                        balloonContentHeader: venue.name,
                        balloonContentBody: displayAddress(venue.address),
                    },
                    { preset: 'islands#orangeSportIcon' },
                );

                placemark.events.add('click', () => {
                    selectVenue(venue);
                    mapModal?.querySelector('[data-modal-action="close"]')?.click();
                });
                yandexMap.geoObjects.add(placemark);
            });

            yandexMap.setBounds(yandexMap.geoObjects.getBounds(), {
                checkZoomRange: true,
                zoomMargin: 40,
            });
            mapMessage.hidden = true;
        } catch (error) {
            mapMessage.textContent = error.message || 'Не удалось загрузить карту площадок.';
        }
    }

    async function loadPreview() {
        const url = previewOpen?.dataset.previewUrl;
        const previewMessage = previewModal?.querySelector('[data-venue-preview-message]');
        const previewContent = previewModal?.querySelector('[data-venue-preview-content]');

        if (!url || !previewModal || !previewMessage || !previewContent) {
            return;
        }

        previewController?.abort();
        const controller = new AbortController();
        previewController = controller;
        previewMessage.textContent = 'Загружаем информацию…';
        previewMessage.hidden = false;
        previewContent.hidden = true;

        try {
            const response = await fetch(url, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: controller.signal,
            });
            const payload = await response.json().catch(() => ({}));

            if (!response.ok || !payload.venue) {
                throw new Error(payload.message || 'Не удалось загрузить площадку.');
            }

            renderPreview(payload.venue);
            previewMessage.hidden = true;
            previewContent.hidden = false;
        } catch (error) {
            if (error.name !== 'AbortError') {
                previewMessage.textContent = error.message || 'Не удалось загрузить площадку.';
            }
        } finally {
            if (previewController === controller) {
                previewController = null;
            }
        }
    }

    function renderPreview(venue) {
        const image = previewModal.querySelector('[data-venue-preview-image]');
        const imageWrap = previewModal.querySelector('[data-venue-preview-image-wrap]');
        const metro = Array.isArray(venue.metro_stations)
            ? venue.metro_stations.map((station) => station.name).filter(Boolean).join(', ')
            : '';

        setText('[data-venue-preview-title]', venue.name || 'Площадка');
        setText('[data-venue-preview-type]', venue.type || '');
        setText('[data-venue-preview-state]', venue.is_open ? 'Открыта' : 'Закрыта');
        setText('[data-venue-preview-address]', venue.address || 'Адрес не указан');
        setText('[data-venue-preview-hours]', venue.today_hours ? `Часы работы: ${venue.today_hours}` : '');
        setOptionalText('[data-venue-preview-metro]', metro ? `Метро: ${metro}` : '');
        setOptionalText('[data-venue-preview-description]', venue.description || '');

        const pageLink = previewModal.querySelector('[data-venue-preview-page]');
        const state = previewModal.querySelector('[data-venue-preview-state]');
        state?.classList.toggle('is-closed', !venue.is_open);

        if (pageLink) {
            pageLink.href = venue.url || '#';
        }

        if (image && imageWrap) {
            imageWrap.hidden = !venue.image_url;
            if (venue.image_url) {
                image.src = venue.image_url;
                image.alt = venue.name || 'Площадка';
            } else {
                image.removeAttribute('src');
                image.alt = '';
            }
        }
    }

    function setText(selector, text) {
        const element = previewModal?.querySelector(selector);
        if (element) {
            element.textContent = text;
        }
    }

    function setOptionalText(selector, text) {
        const element = previewModal?.querySelector(selector);
        if (element) {
            element.textContent = text;
            element.hidden = !text;
        }
    }
}

function displayAddress(address) {
    return String(address || '')
        .replace(/^(?:Россия|Российская Федерация)\s*,\s*/iu, '')
        .trim();
}
