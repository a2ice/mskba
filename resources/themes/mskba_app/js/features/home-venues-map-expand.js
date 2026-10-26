/* Progressive enhancement for the existing published Filament venues-map block.
 * Keep all map HTML/CSS/JS and Yandex state in the CMS untouched.
 * A fallback /venues link works until the real map is ready. */
const mapPlate = document.querySelector('.mskba-venues-map');
if (mapPlate && mapPlate.dataset.expandEnhancement !== '1') {
    const canvas = mapPlate.querySelector('#mskba-venues-map-canvas');
    const toolbar = mapPlate.querySelector('#mskba-venues-map-filters');
    const scrim = mapPlate.querySelector('.mskba-venues-map__scrim');
    const copy = mapPlate.querySelector('.mskba-venues-map__copy');
    const expandLink = copy?.querySelector('a.section-cta[href]');

    if (canvas && toolbar && scrim && copy && expandLink) {
        mapPlate.dataset.expandEnhancement = '1';
        let ready = false;
        let expanded = false;

        const collapse = document.createElement('button');
        collapse.type = 'button';
        collapse.className = 'mskba-venues-map__collapse';
        collapse.tabIndex = -1;
        collapse.setAttribute('aria-label', 'Свернуть карту и вернуть описание');
        collapse.setAttribute('title', 'Вернуть описание карты');
        collapse.setAttribute('aria-controls', canvas.id);
        collapse.setAttribute('aria-expanded', 'false');
        const arrow = document.createElement('span');
        arrow.textContent = '←';
        arrow.setAttribute('aria-hidden', 'true');
        collapse.append(arrow);
        mapPlate.append(collapse);

        const mapIsLoaded = () => Boolean(
            canvas.firstElementChild && toolbar.querySelector('select'),
        );

        // The Page Builder script loads Yandex lazily. Observe only the tiny
        // toolbar, never the frequently mutating tiles or marker subtree.
        const observer = new MutationObserver(() => {
            if (ready || !mapIsLoaded()) return;
            ready = true;
            expandLink.setAttribute('role', 'button');
            expandLink.setAttribute('aria-controls', canvas.id);
            expandLink.setAttribute('aria-expanded', 'false');
            observer.disconnect();
        });
        if (mapIsLoaded()) {
            ready = true;
            expandLink.setAttribute('role', 'button');
            expandLink.setAttribute('aria-controls', canvas.id);
            expandLink.setAttribute('aria-expanded', 'false');
        } else {
            observer.observe(toolbar, { childList: true });
            window.addEventListener('pagehide', () => observer.disconnect(), { once: true });
        }

        function toggle(next) {
            if (next === expanded || !ready) return;
            expanded = next;
            mapPlate.classList.toggle('is-map-expanded', expanded);
            copy.inert = expanded;
            expandLink.setAttribute('aria-expanded', String(expanded));
            collapse.setAttribute('aria-expanded', String(expanded));
            collapse.tabIndex = expanded ? 0 : -1;
            if (expanded) collapse.focus({ preventScroll: true });
            else expandLink.focus({ preventScroll: true });
        }

        expandLink.addEventListener('click', event => {
            // Keep the existing catalogue link when Yandex is pending or fails.
            if (!ready) return;
            event.preventDefault();
            toggle(true);
        });
        expandLink.addEventListener('keydown', event => {
            if (!ready || (event.key !== ' ' && event.key !== 'Spacebar')) return;
            event.preventDefault();
            toggle(true);
        });
        collapse.addEventListener('click', () => toggle(false));
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && expanded) toggle(false);
        });
    }
}
