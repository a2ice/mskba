import { loadYandexMaps } from '../../mskba_dark/js/core/yandex-maps.js';
import { venuePlacemark } from '../../mskba_dark/js/core/venue-map-pins.js';

/* Venue gallery, accessible local dialogs and attendance controls.
 * The gallery is static before hydration and respects reduced motion. */
document.querySelectorAll('[data-venue-gallery]').forEach(gallery => {
    const slides = [...gallery.querySelectorAll('[data-gallery-slide]')];
    const thumbs = [...gallery.querySelectorAll('[data-gallery-thumb]')];
    if (slides.length < 2) return;
    let active = 0;
    let interval = null;
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
    function show(index) {
        active = (index + slides.length) % slides.length;
        slides.forEach((img, i) => {
            img.classList.toggle('is-active', i === active);
            img.setAttribute('aria-hidden', String(i !== active));
        });
        thumbs.forEach((btn, i) => {
            btn.classList.toggle('is-active', i === active);
            btn.setAttribute('aria-pressed', String(i === active));
        });
    }
    function stop() { if (interval) clearInterval(interval); interval = null; }
    function start() {
        stop();
        if (!reduced.matches && !document.hidden) interval = setInterval(() => show(active + 1), 5500);
    }
    thumbs.forEach(btn => btn.addEventListener('click', () => {
        show(Number(btn.dataset.galleryThumb)); start();
    }));
    gallery.addEventListener('mouseenter', stop);
    gallery.addEventListener('mouseleave', start);
    gallery.addEventListener('focusin', stop);
    gallery.addEventListener('focusout', event => {
        if (!gallery.contains(event.relatedTarget)) start();
    });
    document.addEventListener('visibilitychange', start);
    reduced.addEventListener('change', start);
    start();
});

const mapDialog = document.querySelector('[data-venue-map-dialog]');
const playersDialog = document.querySelector('[data-venue-interests-dialog]');
const confirmDialog = document.querySelector('[data-venue-interest-confirm]');
let cancelUrl = '';
let submitButton = null;
for (const dialog of [mapDialog, playersDialog, confirmDialog]) {
    if (!dialog) continue;
    dialog.querySelectorAll('[data-venue-overlay-close]').forEach(button =>
        button.addEventListener('click', () => dialog.close()));
    dialog.addEventListener('click', event => {
        if (event.target === dialog) dialog.close();
    });
}
const canvas = mapDialog?.querySelector('[data-venue-map-frame]');
const nearbyToggle = mapDialog?.querySelector('[data-venue-nearby-toggle]');
const nearbyOptions = mapDialog?.querySelector('[data-venue-nearby-options]');
const nearbyRadius = mapDialog?.querySelector('[data-venue-nearby-radius]');
const nearbyOutput = mapDialog?.querySelector('[data-venue-nearby-distance]');
const nearbyApply = mapDialog?.querySelector('[data-venue-nearby-apply]');
const nearbyStatus = mapDialog?.querySelector('[data-venue-nearby-status]');
let venueMap, nearbyCollection, pendingNearby, mapLoading;
const safeHtml = value => {
    const el = document.createElement('span');
    el.textContent = String(value ?? '');
    return el.innerHTML;
};
function showNearbyMessage(message) {
    if (!nearbyStatus) return;
    nearbyStatus.hidden = !message;
    nearbyStatus.textContent = message;
}
async function initializeVenueMap() {
    if (!canvas || venueMap || mapLoading) return mapLoading;
    const lat = Number(canvas.dataset.lat), lon = Number(canvas.dataset.lon);
    if (!canvas.dataset.lat?.trim() || !canvas.dataset.lon?.trim()
        || !Number.isFinite(lat) || !Number.isFinite(lon)) {
        canvas.textContent = 'Координаты площадки не указаны.';
        return;
    }
    if (!canvas.dataset.mapApiKey) {
        canvas.textContent = 'Яндекс Карты временно недоступны: отсутствует API-ключ.';
        return;
    }
    mapLoading = (async () => {
        await loadYandexMaps(canvas.dataset.mapApiKey);
        await new Promise(resolve => window.ymaps.ready(resolve));
        venueMap = new window.ymaps.Map(canvas, {
            center: [lat, lon], zoom: 15,
            controls: ['zoomControl', 'fullscreenControl'],
        });
        const ownPin = venuePlacemark([lat, lon], {
            hintContent: canvas.dataset.name || 'Площадка',
            balloonContentHeader: safeHtml(canvas.dataset.name || 'Площадка'),
        });
        venueMap.geoObjects.add(ownPin);
        nearbyCollection = new window.ymaps.GeoObjectCollection();
        venueMap.geoObjects.add(nearbyCollection);
        venueMap.container.fitToViewport();
    })();
    try { await mapLoading; }
    catch {
        canvas.textContent = 'Не удалось загрузить интерактивную карту.';
        showNearbyMessage('Проверьте соединение и попробуйте ещё раз.');
    } finally { mapLoading = null; }
}
document.querySelector('[data-venue-map-open]')?.addEventListener('click', () => {
    if (!mapDialog) return;
    mapDialog.showModal();
    void initializeVenueMap();
});
nearbyRadius?.addEventListener('input', () => {
    nearbyOutput.textContent = nearbyRadius.value;
});
nearbyToggle?.addEventListener('change', () => {
    nearbyOptions.hidden = !nearbyToggle.checked;
    if (!nearbyToggle.checked) {
        pendingNearby?.abort();
        nearbyCollection?.removeAll();
        showNearbyMessage('');
        if (venueMap) venueMap.setCenter([Number(canvas.dataset.lat),Number(canvas.dataset.lon)], 15);
    }
});
nearbyApply?.addEventListener('click', async () => {
    if (!nearbyToggle?.checked || !nearbyApply.dataset.nearbyUrl) return;
    await initializeVenueMap();
    if (!venueMap || !nearbyCollection) return;
    const radius = Math.max(1, Math.min(10, Number(nearbyRadius.value) || 3));
    pendingNearby?.abort();
    const controller = new AbortController();
    pendingNearby = controller;
    nearbyApply.disabled = true;
    showNearbyMessage('Ищем площадки рядом…');
    try {
        const url = new URL(nearbyApply.dataset.nearbyUrl, window.location.origin);
        url.searchParams.set('radius_km', String(radius));
        const res = await fetch(url, {headers:{Accept:'application/json'},credentials:'same-origin',signal:controller.signal});
        if (!res.ok) throw new Error('Не удалось получить ближайшие площадки.');
        const body = await res.json();
        if (controller.signal.aborted) return;
        nearbyCollection.removeAll();
        const venues = Array.isArray(body.venues) ? body.venues : [];
        venues.forEach(item => {
            const lat = Number(item.latitude), lon = Number(item.longitude);
            if (!Number.isFinite(lat) || !Number.isFinite(lon)) return;
            const venueUrl = new URL(item.url, window.location.origin);
            if (venueUrl.origin !== window.location.origin) return;
            nearbyCollection.add(venuePlacemark([lat, lon], {
                hintContent: String(item.name || 'Площадка'),
                balloonContentHeader: safeHtml(item.name),
                balloonContentBody: '<a href="' + safeHtml(venueUrl.pathname) + '">Открыть площадку</a>',
            }));
        });
        showNearbyMessage(venues.length ? 'Найдено площадок: ' + venues.length : 'Рядом нет подходящих площадок.');
        const bounds = venueMap.geoObjects.getBounds();
        if (bounds && venues.length) venueMap.setBounds(bounds, {checkZoomRange:true,zoomMargin:48});
    } catch (e) {
        if (e.name !== 'AbortError') showNearbyMessage(e.message);
    } finally {
        if (pendingNearby === controller) {pendingNearby = null;nearbyApply.disabled = false;}
    }
});
mapDialog?.addEventListener('close', () => {
    pendingNearby?.abort();
});
document.querySelector('[data-venue-interests-open]')?.addEventListener('click', () => playersDialog?.showModal());

// Capture clicks before the Vue dialog's delegated handler. A known active
// interest needs confirmation, not creation of a duplicate.
document.addEventListener('click', event => {
    const button = event.target.closest?.('[data-venue-interest-action]');
    if (!button) return;
    const id = Number(button.dataset.interestId);
    if (!id || !button.dataset.cancelUrl) {
        if (playersDialog?.open) playersDialog.close();
        return;
    }
    event.preventDefault();
    event.stopImmediatePropagation();
    cancelUrl = button.dataset.cancelUrl;
    playersDialog?.close();
    confirmDialog?.showModal();
}, true);
document.querySelector('[data-venue-interest-confirm-submit]')?.addEventListener('click', async event => {
    if (!cancelUrl || submitButton) return;
    submitButton = event.currentTarget;
    const error = confirmDialog.querySelector('[data-venue-interest-error]');
    error.hidden = true; submitButton.disabled = true;
    try {
        const response = await fetch(cancelUrl, {
            method: 'DELETE', credentials: 'same-origin',
            headers: {
                Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
        });
        if (!response.ok) throw new Error('Не удалось отменить интерес. Повторите попытку.');
        location.reload();
    } catch (cause) {
        error.textContent = cause.message; error.hidden = false;
    } finally { submitButton.disabled = false; submitButton = null; }
});
