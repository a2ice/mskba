import TomSelect from 'tom-select';
import { loadYandexMaps } from '../core/yandex-maps.js';

document.querySelectorAll('[data-venue-selector]').forEach((container) => {
    const select = container.querySelector('[data-venue-selector-select]');
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
    let activeRequest = 0;
    let yandexMap = null;

    if (!select) {
        return;
    }

    const picker = new TomSelect(select, {
        valueField: 'id',
        labelField: 'name',
        searchField: [],
        create: false,
        maxItems: 1,
        preload: 'focus',
        loadThrottle: 300,
        placeholder: select.dataset.placeholder || 'Начните вводить название, улицу, метро или тег...',
        shouldLoad() {
            return true;
        },
        load(query, callback) {
            loadVenues(query, 30)
                .then(callback)
                .catch(() => callback());
        },
        render: {
            option(data, escape) {
                const details = [
                    data.address,
                    Array.isArray(data.metro_stations) ? data.metro_stations.join(', ') : '',
                    Array.isArray(data.tags) ? data.tags.join(', ') : '',
                ].filter(Boolean).join(' · ');

                return `<div class="venue-selector-option">
                    <strong>${escape(data.name)}</strong>
                    ${details ? `<span>${escape(details)}</span>` : ''}
                </div>`;
            },
            item(data, escape) {
                return `<div>${escape(data.name)}${data.address ? ` — ${escape(data.address)}` : ''}</div>`;
            },
            no_results() {
                return '<div class="no-results">Подходящие площадки не найдены</div>';
            },
        },
    });

    [startInput, durationInput].filter(Boolean).forEach((input) => {
        input.addEventListener('change', () => {
            picker.clear(true);
            picker.clearOptions();
            picker.load('');
        });
    });

    mapOpen?.addEventListener('click', () => {
        window.setTimeout(loadMap, 0);
    });

    async function loadVenues(query = '', limit = 30) {
        const requestId = ++activeRequest;
        const parameters = buildParameters(query, limit);
        const response = await fetch(`${container.dataset.searchUrl}?${parameters.toString()}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        const payload = await response.json().catch(() => ({}));

        if (requestId !== activeRequest) {
            return [];
        }

        if (!response.ok) {
            throw new Error(payload.message || 'Не удалось загрузить площадки.');
        }

        return Array.isArray(payload.venues) ? payload.venues : [];
    }

    function buildParameters(query, limit) {
        const parameters = new URLSearchParams({
            query,
            confirmed_only: container.dataset.confirmedOnly || '0',
            limit: String(limit),
        });

        if (container.dataset.operationalStatus) {
            parameters.set('operational_status', container.dataset.operationalStatus);
        }

        if (startInput?.value && durationInput?.value) {
            parameters.set('starts_at', startInput.value);
            parameters.set('duration_minutes', durationInput.value);
        }

        return parameters;
    }

    async function loadMap() {
        if (!mapElement || !mapMessage) {
            return;
        }

        mapMessage.textContent = 'Загружаем площадки…';
        mapMessage.hidden = false;

        try {
            const venues = (await loadVenues('', 200)).filter(
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
                        balloonContentBody: venue.address || '',
                    },
                    { preset: 'islands#orangeSportIcon' },
                );

                placemark.events.add('click', () => {
                    picker.addOption(venue);
                    picker.setValue(String(venue.id));
                    mapModal.querySelector('[data-modal-action="close"]')?.click();
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
});
