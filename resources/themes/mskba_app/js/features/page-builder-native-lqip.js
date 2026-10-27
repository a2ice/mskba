/*
 * Progressive Page Builder Site Media. On first paint, PHP has already set
 * <img src> and authored CSS background URLs to the tiny WebP derivative.
 * Both use their ORIGINAL CSS geometry. Never clone or move scene layers.
 * Client-side hydration decodes the original off-DOM, then swaps its URL.
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

    const loaded = (src, done, priority = 'auto') => {
        const full = new Image();
        full.decoding = 'async';
        full.fetchPriority = priority;
        full.onload = () => {
            const show = () => {
                if (full.naturalWidth > 0) done(full);
            };
            if (typeof full.decode === 'function') full.decode().then(show, show);
            else show();
        };
        // An invalid full image never displaces a working preview.
        full.src = src;
        if (full.complete && full.naturalWidth > 0) full.onload();
    };

    const hydrated = new WeakSet();
    const dynamicBackgrounds = new WeakMap();

    function hydrateImage(image) {
        if (hydrated.has(image)) return;
        if (image.closest('[data-site-media-progressive]')) return;
        const fullFallback = image.getAttribute('data-mskba-lqip-full-src');
        if (!fullFallback) {
            // Images inserted by published component JS after initial HTML
            // render (not server-rendered) still use the same native approach.
            const entry = imageVersion(image.getAttribute('src'));
            if (!entry || (image.complete && image.naturalWidth > 0)) return;
            image.setAttribute('data-mskba-lqip-full-src', entry.original);
            image.src = entry.preview;
        }
        hydrated.add(image);
        const fullSrc = image.getAttribute('data-mskba-lqip-full-src');
        const picture = image.closest('picture');
        const source = picture && [...picture.querySelectorAll('source[data-mskba-lqip-full-srcset]')]
            .find(node => !node.media || window.matchMedia(node.media).matches);
        const target = source?.getAttribute('data-mskba-lqip-full-srcset') || fullSrc;
        const begin = () => {
            loaded(target, () => {
                if (!image.isConnected) return;
                // Both URLs change on the SAME picture/img DOM nodes; parallax
                // styles and CSS layout do not change, and the full is decoded.
                for (const node of picture?.querySelectorAll('source[data-mskba-lqip-full-srcset]') || []) {
                    node.srcset = node.getAttribute('data-mskba-lqip-full-srcset');
                }
                image.src = fullSrc;
                image.setAttribute('data-mskba-lqip-ready', '1');
            }, image.hasAttribute('data-mskba-lqip-venue-player') ? 'high' : 'auto');
        };
        if (image.loading === 'lazy' && 'IntersectionObserver' in window
            && !image.hasAttribute('data-mskba-lqip-venue-player')) {
            const observer = new IntersectionObserver(entries => {
                if (entries.some(entry => entry.isIntersecting)) {
                    observer.disconnect();
                    begin();
                }
            }, { rootMargin: '300px' });
            observer.observe(image);
        } else begin();
    }

    function hydrateCssBackgrounds() {
        const styles = document.querySelector('style[data-mskba-lqip-css]');
        if (!styles) return;
        for (const { original, preview } of imageUrls) {
            if (!styles.textContent.includes(preview)) continue;
            loaded(original, () => {
                if (styles.isConnected) {
                    styles.textContent = styles.textContent.replaceAll(preview, original);
                }
            }, 'low');
        }
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
        // The original CSS background-size, position, transforms, opacity and
        // z-index stay intact. No absolutely positioned overlay or cloned node.
        element.style.backgroundImage = background.replace(matches[0][1], entry.preview);
        loaded(entry.original, () => {
            if (!element.isConnected || dynamicBackgrounds.get(element) !== entry.original) return;
            element.style.backgroundImage = background;
        }, 'low');
    }

    function scan(node) {
        if (!node || node.nodeType !== 1 || node.id === 'mskba-venues-map-canvas'
            || node.closest?.('#mskba-venues-map-canvas')) return;
        if (node.tagName === 'IMG') hydrateImage(node);
        else hydrateDynamicBackground(node);
        node.querySelectorAll?.('img[data-mskba-lqip-full-src], img[src^="/media/site/"]')
            .forEach(hydrateImage);
        node.querySelectorAll?.('[style*="background-image"]').forEach(hydrateDynamicBackground);
    }

    function start() {
        scan(canvas);
        hydrateCssBackgrounds();
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
