import { loadYandexMaps } from '../../mskba_dark/js/core/yandex-maps.js';
import { venuePlacemark } from '../../mskba_dark/js/core/venue-map-pins.js';
import { venueMapBalloonAction } from '../../../js/components/venue-map-balloon.js';

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
    let referenceVenueId = null;
    let generation = 0;
    const initialContext = { data: {...canvas.dataset}, title: title.textContent, address: address.textContent, nearby: apply.dataset.nearbyUrl, currentLink: dialog.dataset.currentVenueLink };

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
    const referenceCoords = () => referenceVenueId !== null && validCoords(initialContext.data.lat, initialContext.data.lon)
        ? [Number(initialContext.data.lat), Number(initialContext.data.lon)] : null;
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
    const fitMap = (includeNearby = false) => {
        if (!map) return;
        const own = ownCoords();
        const reference = referenceCoords();
        // In the two-venue view the clicked venue is the visual anchor.
        // Work in projected pixels, not geographic degrees: the limiting
        // edge depends on both the direction and the actual viewport size.
        if (own && reference && !includeNearby) {
            const [width, height] = map.container.getSize();
            // 20px free space plus the existing pin's ~30px outer extent.
            const halfWidth = width / 2 - 50;
            const halfHeight = height / 2 - 50;
            if (halfWidth > 0 && halfHeight > 0) {
                const projection = map.options.get('projection');
                const baseZoom = 16;
                const center = projection.toGlobalPixels(own, baseZoom);
                const point = projection.toGlobalPixels(reference, baseZoom);
                const dx = Math.abs(point[0] - center[0]);
                const dy = Math.abs(point[1] - center[1]);
                const factor = Math.min(dx ? halfWidth / dx : Infinity, dy ? halfHeight / dy : Infinity);
                const zoom = Math.max(0, Math.min(20, baseZoom + Math.log2(factor)));
                if (Number.isFinite(zoom)) {
                    map.options.set('avoidFractionalZoom', false);
                    Promise.resolve(map.setCenter(own, zoom, { useMapMargin: false })).catch(() => {});
                    return;
                }
            }
        }
        // Additional nearby results and ordinary maps keep the original
        // all-visible-objects framing, including linked metro stations.
        map.options.set('avoidFractionalZoom', true);
        const bounds = map.geoObjects.getBounds();
        if (bounds) {
            const fittingMap = map;
            Promise.resolve(fittingMap.setBounds(bounds, {
                checkZoomRange: true,
                zoomMargin: [48, 48, 48, 48],
            })).then(() => {
                if (map === fittingMap && fittingMap.getZoom() > 16) fittingMap.setZoom(16);
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
                balloonContentBody: venueMapBalloonAction(canvas.dataset.venueUrl, dialog.dataset.currentVenueLink === 'true'),
            }));
            const reference = referenceCoords();
            if (reference) {
                const name = initialContext.data.name || 'Площадка';
                map.geoObjects.add(venuePlacemark(reference, {
                    hintContent: name,
                    balloonContentHeader: safeHtml(name),
                }, 'reference'));
            }
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
    function setContext(venue, details, referenceId) {
        generation++;
        destroyMap();
        resetNearby();
        activeVenueId = Number(venue.id);
        referenceVenueId = referenceId;
        dialog.dataset.currentVenueLink = 'true';
        const name = String(venue.name || 'Площадка');
        title.textContent = name + ' — на карте';
        address.textContent = String(venue.address || '');
        canvas.dataset.name = name;
        canvas.dataset.venueUrl=String(venue.venue_url || venue.url || '');
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
        if (!item) {
            contextRequest?.abort();
            if (activeVenueId !== null || canvas.dataset.name !== initialContext.data.name) {
                generation++;
                destroyMap();
                resetNearby();
                Object.assign(canvas.dataset, initialContext.data);
                title.textContent = initialContext.title;
                address.textContent = initialContext.address;
                apply.dataset.nearbyUrl = initialContext.nearby;
                dialog.dataset.currentVenueLink = initialContext.currentLink;
                activeVenueId = null;
                referenceVenueId = null;
            }
            show();
            return;
        }

        const id = Number(item.dataset.venueId);
        if (!Number.isSafeInteger(id) || id <= 0) return;
        const referenceId = Number(item.closest('[data-venue-map-reference-id]')?.dataset.venueMapReferenceId);
        const nextReferenceId = Number.isSafeInteger(referenceId) && referenceId > 0 && referenceId !== id
            ? referenceId : null;
        if (activeVenueId === id && referenceVenueId === nextReferenceId && ownCoords()) { show(); return; }

        contextRequest?.abort();
        activeVenueId = null;
        referenceVenueId = null;
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
            const response = await fetch('/api/v1/venues/' + id + '?map=1', {
                signal: controller.signal, headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!response.ok) throw new Error('Не удалось получить данные площадки.');
            const payload = await response.json();
            if (controller.signal.aborted || !dialog.open) return;
            if (!payload.data || !payload.map) throw new Error('Карта площадки недоступна.');
            setContext(payload.data, payload.map, nextReferenceId);
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
    let outsidePointer=null;
    dialog.addEventListener('pointerdown',e=>{outsidePointer=e.target===dialog?e.pointerId:null;});
    dialog.addEventListener('pointerup',e=>{if(e.target===dialog&&outsidePointer===e.pointerId)dialog.close();outsidePointer=null;});
    dialog.addEventListener('pointercancel',()=>outsidePointer=null);
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
                if (referenceVenueId !== null && Number(venue.id) === referenceVenueId) return;
                if (!validCoords(venue.latitude, venue.longitude)) return;
                const action=venueMapBalloonAction(venue.url);
                if(!action)return;
                nearbyCollection.add(venuePlacemark(
                    [Number(venue.latitude), Number(venue.longitude)],
                    {
                        hintContent: String(venue.name || 'Площадка'),
                        balloonContentHeader: safeHtml(venue.name),
                        balloonContentBody: action,
                    },
                ));
            });
            setStatus(venues.length ? 'Найдено площадок: ' + venues.length : 'Рядом нет подходящих площадок.');
            fitMap(true);
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
