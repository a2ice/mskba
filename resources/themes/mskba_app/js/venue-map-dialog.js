import { loadYandexMaps } from '../../mskba_dark/js/core/yandex-maps.js';
import { venuePlacemark } from '../../mskba_dark/js/core/venue-map-pins.js';

/*
 * Shared MSKBA venue map: one dialog, one nearby search and one Yandex map.
 * The detail page supplies server-rendered context; PageBuilder loop items
 * resolve the same public context through GET /api/v1/venues/{id}.
 */
const dialog = document.querySelector('[data-venue-map-dialog]');
if (dialog) {
    const canvas = dialog.querySelector('[data-venue-map-frame]');
    const title = dialog.querySelector('[data-venue-map-title]');
    const address = dialog.querySelector('.app-venue-map-options__address');
    const toggle = dialog.querySelector('[data-venue-nearby-toggle]');
    const options = dialog.querySelector('[data-venue-nearby-options]');
    const radius = dialog.querySelector('[data-venue-nearby-radius]');
    const distance = dialog.querySelector('[data-venue-nearby-distance]');
    const apply = dialog.querySelector('[data-venue-nearby-apply]');
    const status = dialog.querySelector('[data-venue-nearby-status]');

    let map = null;
    let loading = null;
    let nearbyCollection = null;
    let metroCollection = null;
    let request = null;
    let contextRequest = null;
    let activeVenueId = null;
    let generation = 0;

    const safeHtml = value => {
        const el = document.createElement('span');
        el.textContent = String(value ?? '');
        return el.innerHTML;
    };
    const validCoords = (latitude, longitude) => (
        latitude !== null && latitude !== undefined && latitude !== ''
        && longitude !== null && longitude !== undefined && longitude !== ''
        && Number.isFinite(Number(latitude)) && Number.isFinite(Number(longitude))
        && Math.abs(Number(latitude)) <= 90 && Math.abs(Number(longitude)) <= 180
    );
    const ownCoords = () => validCoords(canvas.dataset.lat, canvas.dataset.lon)
        ? [Number(canvas.dataset.lat), Number(canvas.dataset.lon)] : null;
    const metroStations = () => {
        let stations;
        try { stations = JSON.parse(canvas.dataset.metroStations || '[]'); }
        catch { return []; }
        return Array.isArray(stations) ? stations.filter(s =>
            validCoords(s.latitude, s.longitude)) : [];
    };
    const metroColor = station => {
        const color = String(station.line_color ?? station.lineColor ?? '').trim();
        return /^#?[0-9a-f]{6}$/i.test(color) ? '#' + color.replace(/^#/, '') : '#758392';
    };
    const setStatus = message => {
        if (!status) return;
        status.hidden = !message;
        status.textContent = message;
    };
    const resetNearby = () => {
        request?.abort();
        request = null;
        nearbyCollection?.removeAll();
        if (toggle) toggle.checked = false;
        if (options) options.hidden = true;
        if (radius) radius.value = '3';
        if (distance) distance.textContent = '3';
        if (apply) apply.disabled = false;
        setStatus('');
    };
    const destroyMap = () => {
        if (map) { map.destroy(); map = null; }
        nearbyCollection = null;
        metroCollection = null;
        loading = null;
        canvas.replaceChildren();
    };
    const fitMap = () => {
        if (!map) return;
        // The station collection stays in geoObjects when nearby pins are hidden,
        // so the bounds always include the venue and all linked metro stations.
        const bounds = map.geoObjects.getBounds();
        if (bounds) {
            Promise.resolve(map.setBounds(bounds, {
                checkZoomRange: true,
                zoomMargin: [48, 48, 48, 48],
            })).then(() => {
                if (map && map.getZoom() > 16) map.setZoom(16);
            }).catch(() => {});
        } else if (ownCoords()) {
            map.setCenter(ownCoords(), 15);
        }
    };
    async function initialize() {
        if (map || loading) return loading;
        const own = ownCoords();
        if (!own) { canvas.textContent = 'Координаты площадки не указаны.'; return; }
        if (!canvas.dataset.mapApiKey) {
            canvas.textContent = 'Яндекс Карты временно недоступны: отсутствует API-ключ.';
            return;
        }
        const current = generation;
        loading = (async () => {
            await loadYandexMaps(canvas.dataset.mapApiKey);
            await new Promise(resolve => window.ymaps.ready(resolve));
            if (current !== generation || !dialog.open) return;
            map = new window.ymaps.Map(canvas, {
                center: own, zoom: 15, controls: ['zoomControl', 'fullscreenControl'],
            });
            map.geoObjects.add(venuePlacemark(own, {
                hintContent: canvas.dataset.name || 'Площадка',
                balloonContentHeader: safeHtml(canvas.dataset.name || 'Площадка'),
            }));
            metroCollection = new window.ymaps.GeoObjectCollection();
            metroStations().forEach(station => {
                const name = String(station.name || 'Метро');
                const line = String(station.line_name ?? station.lineName ?? '');
                metroCollection.add(new window.ymaps.Placemark(
                    [Number(station.latitude), Number(station.longitude)],
                    {
                        hintContent: name,
                        balloonContentHeader: safeHtml(name),
                        balloonContentBody: safeHtml(line ? 'Метро · ' + line : 'Станция метро'),
                    },
                    { preset: 'islands#circleDotIcon', iconColor: metroColor(station) },
                ));
            });
            map.geoObjects.add(metroCollection);
            nearbyCollection = new window.ymaps.GeoObjectCollection();
            map.geoObjects.add(nearbyCollection);
            map.container.fitToViewport();
            fitMap();
        })();
        try { await loading; }
        catch {
            if (current === generation) {
                canvas.textContent = 'Не удалось загрузить интерактивную карту.';
                setStatus('Проверьте соединение и попробуйте ещё раз.');
            }
        } finally {
            if (current === generation) loading = null;
        }
    }
    function show() {
        if (!dialog.open) dialog.showModal();
        void initialize();
    }
    function setContext(venue, details) {
        generation++;
        destroyMap();
        resetNearby();
        activeVenueId = Number(venue.id);
        const name = String(venue.name || 'Площадка');
        title.textContent = name + ' — на карте';
        address.textContent = String(venue.address || '');
        canvas.dataset.name = name;
        canvas.dataset.lat = venue.latitude == null ? '' : String(venue.latitude);
        canvas.dataset.lon = venue.longitude == null ? '' : String(venue.longitude);
        canvas.dataset.metroStations = JSON.stringify(details.metro_stations || []);
        canvas.dataset.mapApiKey = String(details.api_key || '');
        apply.dataset.nearbyUrl = String(details.nearby_url || '');
    }

    document.addEventListener('click', async event => {
        const trigger = event.target.closest?.('[data-venue-map-open]');
        if (!trigger) return;
        event.preventDefault();
        // The venue detail is already a server-rendered map context.
        const item = trigger.closest('[data-venue-id]');
        if (!item) { show(); return; }

        const id = Number(item.dataset.venueId);
        if (!Number.isSafeInteger(id) || id <= 0) return;
        if (activeVenueId === id && ownCoords()) { show(); return; }

        contextRequest?.abort();
        const controller = new AbortController();
        contextRequest = controller;
        generation++;
        destroyMap();
        resetNearby();
        title.textContent = 'Площадка — на карте';
        address.textContent = '';
        canvas.textContent = 'Загружаем площадку…';
        if (!dialog.open) dialog.showModal();

        try {
            const response = await fetch('/api/v1/venues/' + id, {
                signal: controller.signal, headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!response.ok) throw new Error('Не удалось получить данные площадки.');
            const payload = await response.json();
            if (controller.signal.aborted || !dialog.open) return;
            if (!payload.data || !payload.map) throw new Error('Карта площадки недоступна.');
            setContext(payload.data, payload.map);
            void initialize();
        } catch (error) {
            if (error.name !== 'AbortError' && dialog.open) {
                canvas.textContent = error.message;
            }
        } finally {
            if (contextRequest === controller) contextRequest = null;
        }
    });

    dialog.querySelectorAll('[data-venue-overlay-close]').forEach(button =>
        button.addEventListener('click', () => dialog.close()));
    dialog.addEventListener('click', event => {
        if (event.target === dialog) dialog.close();
    });
    radius?.addEventListener('input', () => {
        distance.textContent = radius.value;
    });
    toggle?.addEventListener('change', () => {
        options.hidden = !toggle.checked;
        if (!toggle.checked) {
            request?.abort();
            nearbyCollection?.removeAll();
            setStatus('');
            fitMap();
        }
    });
    apply?.addEventListener('click', async () => {
        if (!toggle?.checked || !apply.dataset.nearbyUrl) return;
        await initialize();
        if (!map || !nearbyCollection) return;

        request?.abort();
        const controller = new AbortController();
        request = controller;
        const current = generation;
        apply.disabled = true;
        setStatus('Ищем площадки рядом…');
        try {
            const url = new URL(apply.dataset.nearbyUrl, location.origin);
            if (url.origin !== location.origin) throw new Error('Некорректный адрес поиска.');
            url.searchParams.set('radius_km', String(Math.max(1, Math.min(10, Number(radius.value) || 3))));
            const response = await fetch(url, {
                signal: controller.signal, headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!response.ok) throw new Error('Не удалось получить ближайшие площадки.');
            const payload = await response.json();
            if (controller.signal.aborted || current !== generation || !toggle.checked) return;
            nearbyCollection.removeAll();
            const venues = Array.isArray(payload.venues) ? payload.venues : [];
            venues.forEach(venue => {
                if (!validCoords(venue.latitude, venue.longitude)) return;
                const href = new URL(venue.url || '', location.origin);
                if (href.origin !== location.origin || !/^\/venues\/\d+-/.test(href.pathname)) return;
                nearbyCollection.add(venuePlacemark(
                    [Number(venue.latitude), Number(venue.longitude)],
                    {
                        hintContent: String(venue.name || 'Площадка'),
                        balloonContentHeader: safeHtml(venue.name),
                        balloonContentBody: '<a href="' + safeHtml(href.pathname) + '">Открыть площадку</a>',
                    },
                ));
            });
            setStatus(venues.length ? 'Найдено площадок: ' + venues.length : 'Рядом нет подходящих площадок.');
            fitMap();
        } catch (error) {
            if (error.name !== 'AbortError') setStatus(error.message);
        } finally {
            if (request === controller) { request = null; apply.disabled = false; }
        }
    });
    dialog.addEventListener('close', () => {
        generation++;
        request?.abort();
        contextRequest?.abort();
        // Preserve the server-rendered detail context on its own page.
        resetNearby();
        if (map) { map.destroy(); map = null; }
        nearbyCollection = null;
        metroCollection = null;
        loading = null;
    });
}
