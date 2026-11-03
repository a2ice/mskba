/*
 * Cache-first progressive media for Page Builder.
 * The server renders original URLs in <img src>, <picture><source> and
 * CSS backgrounds. Lightweight LQIP thumbnails are background fallbacks,
 * never a substitute src that flashes again on every reload.
 */
const canvas = document.querySelector('.mskba-page-builder');
const manifestNode = document.querySelector('[data-page-builder-lqip-manifest]');

if (canvas && manifestNode) {
    let previews = {};
    try { previews = JSON.parse(manifestNode.textContent || '{}'); } catch { /* no previews */ }
    const origin = window.location.origin;
    const imageUrls = Object.entries(previews).map(([uuid, preview]) => ({
        original: '/media/site/' + uuid,
        preview,
    })).filter(item => /^\/media\/site\/[a-f0-9-]{36}\/lqip$/i.test(item.preview));

    const imageVersion = value => {
        if (!value) return null;
        try {
            const url = new URL(value, window.location.href);
            if (url.origin !== origin) return null;
            return imageUrls.find(x => x.original.toLowerCase() === url.pathname.toLowerCase()) || null;
        } catch { return null; }
    };

    const hydrated = new WeakSet();
    const dynamicBackgrounds = new WeakMap();

    function finishImage(image) {
        const finish = () => {
            if (!image.isConnected || image.naturalWidth <= 0) return;
            // Remove only the preview CSS installed by Page Builder.
            if (image.hasAttribute('data-mskba-lqip-added-background')) {
                image.style.removeProperty('background-image');
                image.style.removeProperty('background-size');
                image.style.removeProperty('background-position');
                image.style.removeProperty('background-repeat');
                image.removeAttribute('data-mskba-lqip-added-background');
            }
            image.setAttribute('data-mskba-lqip-ready', '1');
        };
        const decode = () => {
            if (typeof image.decode === 'function') image.decode().then(finish, finish);
            else finish();
        };
        if (image.complete && image.naturalWidth > 0) decode();
        else image.addEventListener('load', decode, { once: true });
    }

    function hydrateImage(image) {
        if (hydrated.has(image) || image.closest('[data-site-media-progressive]')) return;
        const authoredOriginal = image.getAttribute('data-mskba-lqip-full-src');
        const entry = authoredOriginal ? imageVersion(authoredOriginal)
            : imageVersion(image.getAttribute('src'));
        if (!entry) return;
        hydrated.add(image);

        // Dynamic API images that were not processed on the server still get
        // a fallback if loading is cold. A warm image is never changed.
        if (!authoredOriginal && !(image.complete && image.naturalWidth > 0)
            && image.style && !image.style.backgroundImage) {
            image.style.backgroundImage = 'url("' + entry.preview + '")';
            image.style.backgroundSize = 'contain';
            image.style.backgroundPosition = 'center';
            image.style.backgroundRepeat = 'no-repeat';
            image.setAttribute('data-mskba-lqip-added-background', '1');
        }
        finishImage(image);
    }

    function hydrateSource(source) {
        // Backward compatibility with older server markup only. New markup
        // keeps responsive source URLs pointed at original assets from HTML.
        const full = source.getAttribute('data-mskba-lqip-full-srcset');
        if (full && source.srcset !== full) source.srcset = full;
    }

    function hydrateDynamicBackground(element) {
        if (element.nodeType !== 1 || !element.style) return;
        if (element.closest('#mskba-venues-map-canvas')) return;
        const background = element.style.backgroundImage;
        if (!background || background === 'none') return;
        const matches = [...background.matchAll(/url\(\s*["']?([^"'()]+)["']?\s*\)/gi)];
        if (matches.length !== 1) return;
        const entry = imageVersion(matches[0][1]);
        if (!entry || dynamicBackgrounds.get(element) === entry.original) return;
        dynamicBackgrounds.set(element, entry.original);

        // Original on top of the preview, with no replacement of a painted
        // background during a warm-cache navigation.
        const both = background.replace(matches[0][0],
            'url("' + matches[0][1] + '"),url("' + entry.preview + '")');
        element.style.backgroundImage = both;
        const full = new Image();
        full.decoding = 'async';
        const reveal = () => {
            if (!element.isConnected || dynamicBackgrounds.get(element) !== entry.original) return;
            if (full.naturalWidth > 0) element.style.backgroundImage = background;
        };
        full.onload = () => {
            if (typeof full.decode === 'function') full.decode().then(reveal, reveal);
            else reveal();
        };
        full.src = entry.original;
        if (full.complete && full.naturalWidth > 0) full.onload();
    }

    function scan(node) {
        if (!node || node.nodeType !== 1 || node.id === 'mskba-venues-map-canvas'
            || node.closest?.('#mskba-venues-map-canvas')) return;
        if (node.tagName === 'IMG') hydrateImage(node);
        else hydrateDynamicBackground(node);
        node.querySelectorAll?.('img[data-mskba-lqip-full-src], img[src^="/media/site/"]')
            .forEach(hydrateImage);
        if (node.tagName === 'SOURCE') hydrateSource(node);
        node.querySelectorAll?.('source[data-mskba-lqip-full-srcset]').forEach(hydrateSource);
        node.querySelectorAll?.('[style*="background-image"]').forEach(hydrateDynamicBackground);
    }

    function start() {
        scan(canvas);
        if (typeof MutationObserver !== 'undefined') {
            const observer = new MutationObserver(records => {
                for (const record of records) {
                    if (record.target?.closest?.('#mskba-venues-map-canvas')) continue;
                    if (record.type === 'attributes') {
                        if (record.target.tagName === 'IMG') hydrateImage(record.target);
                        else hydrateDynamicBackground(record.target);
                    } else {
                        for (const node of record.addedNodes) scan(node);
                    }
                }
            });
            observer.observe(canvas, {
                subtree: true, childList: true, attributes: true,
                attributeFilter: ['style', 'src', 'srcset'],
            });
            window.addEventListener('pagehide', () => observer.disconnect(), { once: true });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start, { once: true });
    } else start();
}
