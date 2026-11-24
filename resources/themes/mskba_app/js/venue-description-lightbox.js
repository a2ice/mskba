// Sanitized venue descriptions can link image thumbnails into a shared lightbox.
// Delegation keeps links inserted later working; URLs are never used as HTML.
const descriptionSelector = '.app-venue-description, .venue-show-section__description';
const lightboxSelector = 'a[data-venue-lightbox], a[data-lightbox]';

function imageUrl(anchor) {
    const href = anchor.getAttribute('href');
    if (!href || !anchor.querySelector('img')) return null;
    try {
        const url = new URL(href, window.location.href);
        return ['http:', 'https:'].includes(url.protocol) ? url.href : null;
    } catch { return null; }
}

let dialog = null;
let image = null;
let caption = null;
let previous = null;
let next = null;
let entries = [];
let current = 0;
let trigger = null;

function show(index) {
    if (!entries.length) return;
    current = (index + entries.length) % entries.length;
    const entry = entries[current];
    image.src = entry.url;
    image.alt = entry.alt;
    caption.textContent = entry.alt;
    caption.hidden = !entry.alt;
    previous.hidden = next.hidden = entries.length < 2;
}

function ensureDialog() {
    if (dialog) return;
    dialog = document.createElement('dialog');
    dialog.className = 'venue-description-lightbox';
    dialog.setAttribute('aria-label', 'Просмотр фотографий площадки');
    dialog.innerHTML = `
        <div class="venue-description-lightbox__frame">
            <button type="button" class="venue-description-lightbox__close" aria-label="Закрыть фото">×</button>
            <button type="button" class="venue-description-lightbox__prev" aria-label="Предыдущее фото">‹</button>
            <img class="venue-description-lightbox__image" alt="">
            <button type="button" class="venue-description-lightbox__next" aria-label="Следующее фото">›</button>
            <p class="venue-description-lightbox__caption"></p>
        </div>`;
    document.body.append(dialog);
    image = dialog.querySelector('.venue-description-lightbox__image');
    caption = dialog.querySelector('.venue-description-lightbox__caption');
    previous = dialog.querySelector('.venue-description-lightbox__prev');
    next = dialog.querySelector('.venue-description-lightbox__next');
    previous.addEventListener('click', () => show(current - 1));
    next.addEventListener('click', () => show(current + 1));
    dialog.querySelector('.venue-description-lightbox__close').addEventListener('click', () => dialog.close());
    dialog.addEventListener('keydown', event => {
        if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
        event.preventDefault();
        show(current + (event.key === 'ArrowRight' ? 1 : -1));
    });
    let downOnBackdrop = false;
    dialog.addEventListener('pointerdown', event => { downOnBackdrop = event.target === dialog; });
    dialog.addEventListener('pointerup', event => {
        if (downOnBackdrop && event.target === dialog) dialog.close();
        downOnBackdrop = false;
    });
    dialog.addEventListener('pointercancel', () => { downOnBackdrop = false; });
    dialog.addEventListener('close', () => {
        image.removeAttribute('src');
        trigger?.focus({preventScroll: true});
        trigger = null;
    });
}

document.addEventListener('click', event => {
    // The app-theme rental handler already owns this action. The classic
    // venue theme opens its established occupancy/calendar modal instead.
    const rentalLink = event.target.closest?.('a[data-venue-rental-open]');
    if (rentalLink?.closest(descriptionSelector) && !document.querySelector('[data-rental-search-dialog]')) {
        const firstDay = document.querySelector('[data-venue-occupancy-day]');
        if (firstDay) {
            event.preventDefault();
            firstDay.click();
            return;
        }
    }

    const anchor = event.target.closest?.(lightboxSelector);
    const root = anchor?.closest(descriptionSelector);
    if (!root || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    const url = imageUrl(anchor);
    if (!url) return;

    const group = anchor.getAttribute('data-venue-lightbox') || anchor.getAttribute('data-lightbox') || 'gallery';
    const thumbnails = [...root.querySelectorAll(lightboxSelector)].filter(candidate =>
        (candidate.getAttribute('data-venue-lightbox') || candidate.getAttribute('data-lightbox') || 'gallery') === group
        && imageUrl(candidate)
    );
    entries = thumbnails.map(candidate => ({
        url: imageUrl(candidate),
        alt: candidate.querySelector('img')?.alt || candidate.getAttribute('title') || '',
    }));
    const selected = thumbnails.indexOf(anchor);
    if (selected < 0 || !entries.length) return;
    event.preventDefault();
    ensureDialog();
    trigger = anchor;
    show(selected);
    if (!dialog.open) dialog.showModal();
});
