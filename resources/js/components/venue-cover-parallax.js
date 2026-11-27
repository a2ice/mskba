// Scoped to venue cards; the image alone moves, never the card or its controls.
import '../../css/venue-cover-parallax.css';

const grids = '.app-venues-catalog__items:not(.app-venues-catalog__items--list), .venues-catalog-results--cards';
const covers = ':scope > .app-venues-catalog__card > .app-venues-catalog__photo, :scope > .venue-catalog-item--card > .venue-catalog-item__image';
const motionQuery = '(max-width: 640px) and (prefers-reduced-motion: no-preference)';

export function venueCoverShift(top, height) {
    if (!Number.isFinite(top) || !Number.isFinite(height) || height <= 0) return 0;
    const t = Math.max(0, Math.min(1, (0.85 * height - top) / (0.70 * height)));
    // Ease in/out at the boundaries; a position-derived value is reversible.
    // Reveal the top of the oversized image as the card approaches the top.
    return -10 * (1 - t * t * (3 - 2 * t));
}

function init() {
    const roots = [...document.querySelectorAll(grids)];
    if (!roots.length || !window.IntersectionObserver || !window.MutationObserver || !window.matchMedia) return;

    const motion = window.matchMedia(motionQuery);
    const registered = new Map();
    const visible = new Set();
    const mutations = [];
    let frame = null;
    let observer = null;

    function clear(photo) {
        photo.classList.remove('venue-cover-parallax');
        photo.style.removeProperty('--venue-cover-shift');
    }

    function paint() {
        frame = null;
        const viewport = window.visualViewport;
        const height = viewport?.height || window.innerHeight;
        const offset = viewport?.offsetTop || 0;
        const enabled = motion.matches && (viewport?.scale || 1) <= 1.01;
        const gridState = new Map();
        const updates = [];
        // Batch geometry reads before writes; only nearby cards are measured.
        for (const photo of visible) {
            const grid = registered.get(photo);
            if (!grid || !photo.isConnected || !enabled) {
                updates.push([photo, null]);
                continue;
            }
            if (!gridState.has(grid)) {
                const columns = window.getComputedStyle(grid).gridTemplateColumns.trim();
                gridState.set(grid, columns !== 'none' && columns !== '' && !/\s/.test(columns));
            }
            const rect = photo.getBoundingClientRect();
            const shift = gridState.get(grid) && rect.width > 0 && rect.height > 0
                ? venueCoverShift(rect.top - offset, height) : null;
            updates.push([photo, shift]);
        }
        for (const [photo, shift] of updates) {
            if (shift === null) clear(photo);
            else {
                photo.style.setProperty('--venue-cover-shift', shift.toFixed(3) + '%');
                photo.classList.add('venue-cover-parallax');
            }
        }
    }

    function schedule() {
        if (observer && frame === null && visible.size) frame = window.requestAnimationFrame(paint);
    }

    function reconcile(grid) {
        const next = new Set(grid.querySelectorAll(covers));
        for (const [photo, owner] of registered) {
            if (owner === grid && !next.has(photo)) {
                observer.unobserve(photo);
                registered.delete(photo);
                visible.delete(photo);
                clear(photo);
            }
        }
        for (const photo of next) {
            if (registered.has(photo)) continue;
            registered.set(photo, grid);
            observer.observe(photo);
        }
        schedule();
    }

    function stop() {
        observer?.disconnect();
        observer = null;
        mutations.splice(0).forEach(item => item.disconnect());
        if (frame !== null) window.cancelAnimationFrame(frame);
        frame = null;
        registered.forEach((_, photo) => clear(photo));
        registered.clear();
        visible.clear();
    }

    function sync() {
        if (!motion.matches) { stop(); return; }
        if (observer) { schedule(); return; }
        observer = new window.IntersectionObserver(entries => {
            if (!observer || !motion.matches) return;
            for (const entry of entries) {
                if (!registered.has(entry.target)) continue;
                if (entry.isIntersecting) visible.add(entry.target);
                else { visible.delete(entry.target); clear(entry.target); }
            }
            schedule();
        }, { rootMargin: '100px 0px' });
        for (const grid of roots) {
            reconcile(grid);
            const mutation = new window.MutationObserver(() => {
                if (observer) reconcile(grid);
            });
            // The infinite catalog appends direct card children.
            mutation.observe(grid, { childList: true });
            mutations.push(mutation);
        }
    }

    if (motion.addEventListener) motion.addEventListener('change', sync);
    else motion.addListener(sync);
    window.addEventListener('scroll', schedule, { passive: true });
    window.addEventListener('resize', sync, { passive: true });
    window.addEventListener('pageshow', sync);
    window.visualViewport?.addEventListener('resize', schedule, { passive: true });
    window.visualViewport?.addEventListener('scroll', schedule, { passive: true });
    sync();
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
    else init();
}
