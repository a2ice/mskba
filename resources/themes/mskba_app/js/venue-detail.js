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

// Follow scroll position and keep hash targets visible below the sticky bars.
const sectionNav = document.querySelector('[data-venue-section-nav]');
if (sectionNav) {
    const links = [...sectionNav.querySelectorAll('a[href^="#"]')];
    const sections = links.map(link => document.getElementById(link.hash.slice(1)));
    const header = document.querySelector('[data-app-header]');
    const breadcrumbs = document.querySelector('[data-app-context-bar]');
    let scheduled = false;

    function navOffset() {
        return (header?.getBoundingClientRect().height || 0)
            + (breadcrumbs?.getBoundingClientRect().height || 0)
            + sectionNav.getBoundingClientRect().height;
    }

    function refreshNavHeight() {
        document.documentElement.style.setProperty(
            '--app-venue-nav-height', Math.ceil(sectionNav.getBoundingClientRect().height) + 'px',
        );
        scheduleActiveUpdate();
    }

    function updateActiveSection() {
        scheduled = false;
        const threshold = navOffset() + 16;
        let active = sections[0];
        for (const section of sections) {
            if (section && section.getBoundingClientRect().top <= threshold) active = section;
            else break;
        }
        links.forEach((link, index) => {
            if (sections[index] === active) link.setAttribute('aria-current', 'location');
            else link.removeAttribute('aria-current');
        });
        const activeLink = links[sections.indexOf(active)];
        if (activeLink && sectionNav.scrollWidth > sectionNav.clientWidth) {
            const left = activeLink.offsetLeft;
            const right = left + activeLink.offsetWidth;
            if (left < sectionNav.scrollLeft || right > sectionNav.scrollLeft + sectionNav.clientWidth) {
                sectionNav.scrollTo({ left: Math.max(0, left - 12), behavior: 'auto' });
            }
        }
    }

    function scheduleActiveUpdate() {
        if (scheduled) return;
        scheduled = true;
        window.requestAnimationFrame(updateActiveSection);
    }

    links.forEach(link => link.addEventListener('click', () => {
        links.forEach(other => other.removeAttribute('aria-current'));
        link.setAttribute('aria-current', 'location');
    }));
    window.addEventListener('scroll', scheduleActiveUpdate, { passive: true });
    window.addEventListener('resize', refreshNavHeight, { passive: true });
    window.addEventListener('hashchange', scheduleActiveUpdate);
    if (typeof ResizeObserver !== 'undefined') new ResizeObserver(refreshNavHeight).observe(sectionNav);
    refreshNavHeight();
}

const mapDialog = document.querySelector('[data-venue-map-dialog]');
const playersDialog = document.querySelector('[data-venue-interests-dialog]');
const slotDialog = document.querySelector('[data-venue-slot-dialog]');
const createEventDialog = document.querySelector('[data-venue-create-event-dialog]');
const confirmDialog = document.querySelector('[data-venue-interest-confirm]');
let cancelUrl = '';
let submitButton = null;
for (const dialog of [mapDialog, playersDialog, slotDialog, createEventDialog, confirmDialog]) {
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
document.querySelector('[data-venue-create-event-open]')?.addEventListener('click', () => createEventDialog?.showModal());

// Occupied time is always clickable, but private bookings reveal no event
// details. Joining is offered only when the server marks a public event open.
document.querySelectorAll('[data-venue-slot-open]').forEach(button => {
    button.addEventListener('click', () => {
        if (!slotDialog) return;
        const eventUrl = button.dataset.eventUrl || '';
        const joinUrl = button.dataset.eventJoinUrl || '';
        const joinState = button.dataset.joinState || 'unavailable';
        const canJoin = button.dataset.canJoin === 'true' && !!eventUrl && !!joinUrl;
        const canRespondTentative = button.dataset.canRespondTentative === 'true' && !!eventUrl && !!joinUrl;
        const eventLink = slotDialog.querySelector('[data-venue-slot-event-link]');
        const joinForm = slotDialog.querySelector('[data-venue-slot-join-form]');
        const tentativeForm = slotDialog.querySelector('[data-venue-slot-tentative-form]');
        const guestLink = slotDialog.querySelector('[data-venue-slot-guest-link]');
        const hasEvent = !!eventUrl;

        slotDialog.querySelector('[data-venue-slot-title]').textContent =
            hasEvent ? (button.dataset.eventTitle || button.dataset.eventType || 'Мероприятие') : 'Занятое время';
        slotDialog.querySelector('[data-venue-slot-time]').textContent =
            [button.dataset.slotDay, button.dataset.slotTime].filter(Boolean).join(' · ');
        const messages = {
            unavailable: 'Детали этой брони не опубликованы. Присоединение недоступно.',
            ended: 'Мероприятие уже завершилось. Изменить участие нельзя.',
            organizer: 'Ты организатор этого мероприятия. Управлять им можно на странице мероприятия.',
            joined: 'Ты уже участвуешь в мероприятии.',
            full: 'Все места заняты. Можно ответить «Думаю», но это не резервирует место.',
            reconfirm: 'Условия мероприятия изменились. Нужно подтвердить участие повторно.',
            open: 'Набор участников открыт.',
        };
        slotDialog.querySelector('[data-venue-slot-message]').textContent =
            joinState === 'full' && !tentativeForm
                ? 'Все места заняты. После входа можно ответить «Думаю», но это не резервирует место.'
                : (messages[joinState] || messages.unavailable);

        if (eventLink) {
            eventLink.hidden = !hasEvent;
            if (hasEvent) eventLink.href = eventUrl;
            else eventLink.removeAttribute('href');
        }
        if (joinForm) {
            joinForm.hidden = !canJoin;
            if (canJoin) joinForm.action = joinUrl;
            else joinForm.removeAttribute('action');
            const methodField = joinForm.querySelector('[data-venue-slot-join-method]');
            if (methodField) methodField.value = button.dataset.eventJoinMethod === 'POST'
                ? 'POST' : 'PATCH';
            const joinLabel = joinForm.querySelector('[data-venue-slot-join-label]');
            if (joinLabel) joinLabel.textContent = joinState === 'reconfirm'
                ? 'Подтвердить участие' : 'Присоединиться';
        }
        if (tentativeForm) {
            tentativeForm.hidden = !canRespondTentative;
            if (canRespondTentative) tentativeForm.action = joinUrl;
            else tentativeForm.removeAttribute('action');
        }
        if (guestLink) {
            // The public event page cannot capture guest "Думаю" responses
            // for a full event, so avoid an action that leads to a dead end.
            guestLink.hidden = !canJoin;
            if (canJoin) {
                guestLink.href = eventUrl;
                guestLink.textContent = 'Присоединиться';
            } else guestLink.removeAttribute('href');
        }
        slotDialog.showModal();
    });
});

// Follow the actual sticky navigation rather than a hard-coded height:
// navigation can wrap and theme/header dimensions can change independently.
const venueSidebar = document.querySelector('.app-venue-page__aside');
const venueAnchors = document.querySelector('.app-venue-page__anchors');
if (venueSidebar && venueAnchors) {
    let pending = false;
    const updateSidebarPosition = () => {
        pending = false;
        if (!venueSidebar.getClientRects().length) return;
        const navBottom = venueAnchors.getBoundingClientRect().bottom;
        const contextBottom = document.querySelector('.app-context-bar')?.getBoundingClientRect().bottom || 0;
        const headerBottom = document.querySelector('.site-header')?.getBoundingClientRect().bottom || 0;
        const fixedHeaderBottom = Math.max(contextBottom, headerBottom, 0);
        const top = Math.ceil(Math.max(navBottom, fixedHeaderBottom) + 15);
        venueSidebar.style.setProperty('--venue-sidebar-sticky-top', top + 'px');
        venueAnchors.closest('.app-venue-page')?.style.setProperty('--app-venue-nav-height', venueAnchors.offsetHeight + 'px');
        venueAnchors.classList.toggle('is-stuck',
            Math.abs(venueAnchors.getBoundingClientRect().top - fixedHeaderBottom) <= 2
            && window.scrollY > 0);
    };
    const scheduleSidebarUpdate = () => {
        if (pending) return;
        pending = true;
        window.requestAnimationFrame(updateSidebarPosition);
    };
    window.addEventListener('scroll', scheduleSidebarUpdate, { passive: true });
    window.addEventListener('resize', scheduleSidebarUpdate);
    if ('ResizeObserver' in window) new ResizeObserver(scheduleSidebarUpdate).observe(venueAnchors);
    scheduleSidebarUpdate();
}

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
        if (!response.ok) throw new Error('Не удалось отменить подписку. Повторите попытку.');
        location.reload();
    } catch (cause) {
        error.textContent = cause.message; error.hidden = false;
    } finally { submitButton.disabled = false; submitButton = null; }
});
