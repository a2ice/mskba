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
document.querySelector('[data-venue-map-open]')?.addEventListener('click', () => {
    if (!mapDialog) return;
    const container = mapDialog.querySelector('[data-venue-map-frame]');
    if (container && !container.firstElementChild) {
        const lat = Number(container.dataset.lat), lon = Number(container.dataset.lon);
        if (Number.isFinite(lat) && Number.isFinite(lon) && lat >= -90 && lat <= 90 && lon >= -180 && lon <= 180) {
            const iframe = document.createElement('iframe');
            const url = new URL('https://yandex.ru/map-widget/v1/');
            url.searchParams.set('ll', lon + ',' + lat);
            url.searchParams.set('pt', lon + ',' + lat + ',pm2rdm');
            url.searchParams.set('z', '16');
            iframe.src = url.toString(); iframe.loading = 'lazy';
            iframe.title = 'Яндекс Карты: ' + (container.dataset.name || 'Площадка');
            iframe.referrerPolicy = 'strict-origin-when-cross-origin';
            iframe.allowFullscreen = true;
            container.append(iframe);
        } else container.textContent = 'Координаты площадки пока не указаны.';
    }
    mapDialog.showModal();
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
