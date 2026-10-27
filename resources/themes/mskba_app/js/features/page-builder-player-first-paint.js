/*
 * Native first-paint LQIP: the existing player <img> itself displays a tiny
 * WebP from server-rendered markup, in its CSS-defined position. Only swap its
 * src after a separate full-resolution image is fully loaded and decoded.
 * Never clone/reposition the player or postpone its visibility until JS.
 */
function hydratePlayerImages() {
    for (const player of document.querySelectorAll('img[data-mskba-player-full-src]')) {
        if (player.dataset.mskbaPlayerHydrated === '1') continue;
        player.dataset.mskbaPlayerHydrated = '1';

        const fullSrc = player.getAttribute('data-mskba-player-full-src');
        if (!fullSrc) continue;
        const full = new Image();
        full.decoding = 'async';
        // The user may enter directly on #venues, where the player becomes
        // first-screen content. The LQIP is already eagerly painted.
        if (window.location.hash === '#venues' || window.location.hash === '#venues-scene') {
            full.fetchPriority = 'high';
        }
        full.onload = () => {
            const swap = () => {
                if (!player.isConnected || full.naturalWidth < 1) return;
                // Do not trigger another network dependency or replace a
                // positioned element: decoded full.src is already in cache.
                player.src = full.src;
                player.dataset.mskbaPlayerLoaded = '1';
            };
            if (typeof full.decode === 'function') full.decode().then(swap, () => {
                if (full.naturalWidth > 0) swap();
            });
            else swap();
        };
        // On error, the small preview remains in place. Nothing disappears.
        full.src = fullSrc;
        if (full.complete && full.naturalWidth > 0) full.onload();
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', hydratePlayerImages, { once: true });
} else {
    hydratePlayerImages();
}
