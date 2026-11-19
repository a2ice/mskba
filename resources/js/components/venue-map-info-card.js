/*
 * Shared MSKBA map venue card for Page Builder home map and Event-first wizard.
 * The source of title, media, address, tags, and details action is identical;
 * the wizard adds the explicit "Выбрать" action and omits the overlay badge.
 */
const SYMBOLS = Object.freeze({
    close: '<path d="M18 6 6 18M6 6l12 12"/>',
    pin: '<path d="M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/>',
    arrow: '<path d="M5 12h14m-6-6 6 6-6 6"/>',
    basketball: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3v18M5.6 5.6c6.4 2 8.3 9.1 8.8 14M18.4 5.6c-6.4 2-8.3 9.1-8.8 14"/>',
});

function icon(name) {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('width', '24');
    svg.setAttribute('height', '24');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '2');
    svg.setAttribute('stroke-linecap', 'round');
    svg.setAttribute('stroke-linejoin', 'round');
    svg.setAttribute('aria-hidden', 'true');
    svg.innerHTML = SYMBOLS[name] || SYMBOLS.pin;
    return svg;
}

function validPublicUrl(value) {
    if (!value) return '';
    try {
        const url = new URL(String(value), document.baseURI);
        return ['http:', 'https:'].includes(url.protocol) && url.origin === location.origin
            ? url.href : '';
    } catch {
        return '';
    }
}

function normalizeTags(venue, matchedCourt) {
    if (Array.isArray(venue.display_tags)) {
        return [...new Set(venue.display_tags.filter(Boolean).map(String))];
    }
    return [...new Set([
        venue.has_free_court ? (venue.has_paid_court ? 'Есть бесплатные залы' : 'Бесплатно') : null,
        venue.has_paid_court ? (venue.has_free_court ? 'Есть платные залы' : 'Платно') : null,
        venue.has_approval_court ? 'Есть залы с подтверждением' : null,
        !venue.has_free_court && !venue.has_paid_court ? 'Условия уточняются' : null,
        matchedCourt?.name ? 'Подходящий зал: ' + matchedCourt.name : null,
        ...(Array.isArray(venue.surfaces) ? venue.surfaces : []).slice(0, 1)
            .map(surface => typeof surface === 'string' ? surface : surface?.label),
        ...(Array.isArray(venue.amenities) ? venue.amenities : []).slice(0, 3),
    ].filter(Boolean).map(String))];
}

function mediaLoadingLayer() {
    const layer = document.createElement('div');
    layer.className = 'mskba-venue-map-card__media-loading';
    layer.setAttribute('role', 'status');
    layer.setAttribute('aria-label', 'Загружаем фотографию площадки');
    const spinner = document.createElement('span');
    spinner.className = 'image-upload-loading__spinner';
    spinner.setAttribute('aria-hidden', 'true');
    layer.append(spinner);
    return layer;
}

function placeholder() {
    const box = document.createElement('div');
    box.className = 'mskba-venues-map__card-media-placeholder';
    box.append(icon('basketball'), document.createTextNode('Фото площадки пока нет'));
    return box;
}

/**
 * The one public venue-info component; actions differ by context only.
 *
 * @param {Object} venue Search API or homepage map venue representation
 * @param {Object} options {onClose, onSelect?, compact?, showBadge?, accessFilter?, actionClass?, mediaPending?}
 */
export function renderVenueMapInfoCard(venue, options = {}) {
    const matches = options.accessFilter === 'Бесплатно' ? venue.court_access?.free
        : options.accessFilter === 'Платно' ? venue.court_access?.paid
            : options.accessFilter === 'По подтверждению' ? venue.court_access?.approval : null;
    const matchedCourt = Array.isArray(matches) ? matches[0] : null;

    const article = document.createElement('article');
    article.className = 'mskba-venues-map__venue-card mskba-venue-map-card'
        + (options.compact ? ' mskba-venue-map-card--compact' : '');
    article.setAttribute('aria-label', 'Карточка площадки ' + String(venue.name || ''));

    const closeButton = document.createElement('button');
    closeButton.type = 'button';
    closeButton.className = 'mskba-venues-map__card-close';
    closeButton.setAttribute('aria-label', 'Закрыть карточку площадки');
    closeButton.append(icon('close'));
    closeButton.addEventListener('click', () => options.onClose?.());

    const media = document.createElement('div');
    media.className = 'mskba-venues-map__card-media';
    const imageUrl = validPublicUrl(venue.image_url || venue.imageUrl);
    if (imageUrl) {
        const img = document.createElement('img');
        const loadingLayer = mediaLoadingLayer();
        img.alt = 'Фото площадки: ' + String(venue.name || '');
        img.loading = 'eager';
        img.decoding = 'async';
        img.hidden = true;
        const showImage = () => {
            if (!img.naturalWidth) return;
            img.hidden = false;
            loadingLayer.remove();
        };
        img.addEventListener('load', showImage, {once:true});
        img.addEventListener('error', () => {
            loadingLayer.replaceWith(placeholder());
            img.remove();
        }, {once:true});
        media.append(img, loadingLayer);
        img.src = imageUrl;
        if (img.complete && img.naturalWidth > 0) showImage();
    } else {
        media.append(options.mediaPending ? mediaLoadingLayer() : placeholder());
    }
    if (options.showBadge !== false) {
        const badge = document.createElement('span');
        badge.className = 'mskba-venues-map__card-label';
        badge.textContent = 'Площадка MSKBA';
        media.append(badge);
    }

    const body = document.createElement('div');
    body.className = 'mskba-venues-map__card-body';
    const name = document.createElement('h3');
    name.className = 'mskba-venues-map__card-name';
    name.textContent = String(venue.name || 'Баскетбольная площадка');

    const address = document.createElement('p');
    address.className = 'mskba-venues-map__card-address';
    address.append(icon('pin'), document.createTextNode(String(venue.address || 'Адрес не указан')));
    body.append(name, address);

    const tags = normalizeTags(venue, matchedCourt);
    if (tags.length) {
        const list = document.createElement('div');
        list.className = 'mskba-venues-map__card-tags';
        tags.forEach(label => {
            const tag = document.createElement('span');
            tag.className = 'mskba-venues-map__card-tag';
            tag.textContent = label;
            list.append(tag);
        });
        body.append(list);
    }

    const actions = document.createElement('div');
    actions.className = 'mskba-venues-map__card-actions';
    const detailsUrl = validPublicUrl(matchedCourt?.url || venue.url);
    if (detailsUrl) {
        const link = document.createElement('a');
        link.className = 'mskba-venues-map__card-link';
        link.href = detailsUrl;
        link.append(document.createTextNode(matchedCourt ? 'Открыть зал' : 'Подробнее'), icon('arrow'));
        actions.append(link);
    }
    if (typeof options.onSelect === 'function') {
        const select = document.createElement('button');
        select.type = 'button';
        select.className = options.actionClass || 'button primary';
        select.dataset.venueMapChoose = String(venue.id || '');
        select.textContent = 'Выбрать';
        select.addEventListener('click', () => options.onSelect(venue));
        actions.append(select);
    }
    body.append(actions);
    article.append(closeButton, media, body);
    return article;
}

// The already-published Page Builder script currently builds its own card.
// Normalize that DOM in place without changing editor-owned JS/CSS or the
// marker's selected/close state. The original close control retains its
// listener in memory so the map's active pin is also reset correctly.
export function attachSharedHomeVenueCards(root = document) {
    const mount = root.querySelector('#mskba-venues-map-card');
    if (!mount || typeof MutationObserver === 'undefined') return false;
    const sync = () => {
        const original = mount.querySelector('.mskba-venues-map__venue-card');
        if (!original || original.classList.contains('mskba-venue-map-card')) return;
        const close = original.querySelector('.mskba-venues-map__card-close');
        const media = original.querySelector('.mskba-venues-map__card-media img');
        const action = original.querySelector('.mskba-venues-map__card-link');
        const normalized = {
            name: original.querySelector('.mskba-venues-map__card-name')?.textContent || '',
            address: original.querySelector('.mskba-venues-map__card-address')?.textContent || '',
            image_url: media?.src || '',
            url: action?.href || '',
            display_tags: [...original.querySelectorAll('.mskba-venues-map__card-tag')]
                .map(tag => tag.textContent).filter(Boolean),
        };
        // Reuse the already loaded image element when the same pin is reopened.
        // Its DOM survives temporary removal from the map mount.
        const cacheKey = JSON.stringify(normalized);
        let entry = cachedCards.get(cacheKey);
        if (!entry) {
            entry = {close: null, node: null};
            entry.node = renderVenueMapInfoCard(normalized, {
                showBadge: true,
                onClose: () => entry.close?.click(),
            });
            cachedCards.set(cacheKey, entry);
            if (cachedCards.size > 30) cachedCards.delete(cachedCards.keys().next().value);
        }
        entry.close = close;
        mount.replaceChildren(entry.node);
    };
    const cachedCards = new Map();
    const observer = new MutationObserver(sync);
    observer.observe(mount, {childList:true});
    sync();
    window.addEventListener('pagehide', () => observer.disconnect(), {once:true});
    return true;
}
