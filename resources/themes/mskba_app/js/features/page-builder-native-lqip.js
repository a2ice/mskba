/*
 * LQIP-first progressive media for Page Builder.
 *
 * The initial HTML/CSS references only tiny previews, so window.load (and
 * the homepage loading layer) is not held by large originals. The originals
 * begin loading asynchronously AFTER that event, then replace thumbnails
 * only after a successful decode. Missing previews retain their normal URL.
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
    const pendingImages = new Set();
    const pendingBackgrounds = new Set();
    const dynamicBackgrounds = new WeakMap();
    let firstPaintReady = false;

    function finishImage(image) {
        const finish = () => {
            if (!image.isConnected || image.naturalWidth <= 0) return;
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
            if (typeof image.decode === 'function') {
                image.decode().then(finish, () => {
                    if (image.naturalWidth > 0) finish();
                });
            } else finish();
        };
        if (image.complete && image.naturalWidth > 0) decode();
        else image.addEventListener('load', decode, { once: true });
    }

    function originalFromSrcset(srcset) {
        return srcset?.trim().split(/\s+/)[0] || null;
    }

    function preloadOriginal(image) {
        if (!image.isConnected) return;
        const full = image.getAttribute('data-mskba-lqip-full-src');
        if (!full || image.getAttribute('src') === full) {
            finishImage(image);
            return;
        }

        const picture = image.closest('picture');
        const sources = [...(picture?.querySelectorAll('source[data-mskba-lqip-full-srcset]') || [])];
        const activeSource = sources.find(source =>
            !source.media || window.matchMedia(source.media).matches);
        const selected = originalFromSrcset(activeSource?.getAttribute('data-mskba-lqip-full-srcset')) || full;
        const original = new Image();
        original.decoding = 'async';

        const promote = () => {
            if (!image.isConnected || original.naturalWidth <= 0) return;
            sources.forEach(source => {
                const srcset = source.getAttribute('data-mskba-lqip-full-srcset');
                if (srcset) {
                    source.srcset = srcset;
                    source.removeAttribute('data-mskba-lqip-full-srcset');
                }
            });
            const imgSrcset = image.getAttribute('data-mskba-lqip-full-srcset');
            if (imgSrcset) {
                image.srcset = imgSrcset;
                image.removeAttribute('data-mskba-lqip-full-srcset');
            }
            // Only promote once the selected original has usable pixels.
            image.src = full;
            finishImage(image);
        };
        original.onload = () => {
            if (typeof original.decode === 'function') {
                original.decode().then(promote, () => {
                    if (original.naturalWidth > 0) promote();
                });
            } else promote();
        };
        // A failed original must never replace a working LQIP.
        original.onerror = () => {};
        original.src = selected;
        if (original.complete && original.naturalWidth > 0) original.onload();
    }

    const originalObserver = typeof IntersectionObserver !== 'undefined'
        ? new IntersectionObserver(entries => {
            for (const entry of entries) {
                if (!entry.isIntersecting) continue;
                originalObserver.unobserve(entry.target);
                preloadOriginal(entry.target);
            }
        }, { rootMargin: '400px' }) : null;

    function scheduleOriginal(image) {
        if (!firstPaintReady) {
            pendingImages.add(image);
            return;
        }
        if (image.loading === 'lazy' && originalObserver) {
            originalObserver.observe(image);
        } else {
            preloadOriginal(image);
        }
    }

    function hydrateImage(image) {
        if (hydrated.has(image) || image.closest('[data-site-media-progressive]')) return;
        const full = image.getAttribute('data-mskba-lqip-full-src');
        const entry = imageVersion(full || image.getAttribute('src'));
        if (!entry) return;
        hydrated.add(image);

        if (full && image.getAttribute('src') !== full) {
            scheduleOriginal(image);
            return;
        }

        // Backward compatibility for dynamic API images that already have the
        // original src. They may still use a CSS fallback during decoding.
        if (!(image.complete && image.naturalWidth > 0)
            && image.style && !image.style.backgroundImage) {
            image.style.backgroundImage = 'url("' + entry.preview + '")';
            image.style.backgroundSize = 'contain';
            image.style.backgroundPosition = 'center';
            image.style.backgroundRepeat = 'no-repeat';
            image.setAttribute('data-mskba-lqip-added-background', '1');
        }
        finishImage(image);
    }

    function hydrateStaticCss() {
        const css = document.querySelector('style[data-mskba-lqip-css]');
        if (!css) return;

        // Decode originals off-DOM before replacing URLs. This preserves
        // authored background layers, shorthand sizing, gradients and masks:
        // no duplicate background layer or blank frame is introduced.
        const known = new Set();
        const pattern = /url\(\s*["']?\/media\/site\/([a-f0-9-]{36})\/lqip["']?\s*\)/gi;
        for (const match of css.textContent.matchAll(pattern)) {
            const uuid = match[1].toLowerCase();
            if (known.has(uuid)) continue;
            known.add(uuid);
            const entry = imageVersion('/media/site/' + uuid);
            if (!entry) continue;
            const full = new Image();
            full.decoding = 'async';
            const promote = () => {
                if (full.naturalWidth <= 0) return;
                css.textContent = css.textContent.replace(
                    new RegExp('url\\(\\s*["\\u0027]?/media/site/' + uuid + '/lqip["\\u0027]?\\s*\\)', 'gi'),
                    'url("' + entry.original + '")',
                );
            };
            full.onload = () => {
                if (typeof full.decode === 'function') {
                    full.decode().then(promote, () => {
                        if (full.naturalWidth > 0) promote();
                    });
                } else promote();
            };
            full.onerror = () => {};
            full.src = entry.original;
            if (full.complete && full.naturalWidth > 0) full.onload();
        }
    }

    function hydrateDynamicBackground(element) {
        if (element.nodeType !== 1 || !element.style
            || element.closest?.('#mskba-venues-map-canvas')) return;
        if (!firstPaintReady) {
            pendingBackgrounds.add(element);
            return;
        }
        const background = element.style.backgroundImage;
        if (!background || background === 'none') return;
        const matches = [...background.matchAll(/url\(\s*["']?([^"'()]+)["']?\s*\)/gi)];
        if (matches.length !== 1) return;
        const entry = imageVersion(matches[0][1]);
        if (!entry || dynamicBackgrounds.get(element) === entry.original) return;
        dynamicBackgrounds.set(element, entry.original);

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
        full.onerror = () => {};
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

        const upgradeAfterInitialPaint = () => {
            // The loading layer's load handler gets its chance to close first.
            // Neither image preloads nor full CSS backgrounds start before it.
            window.setTimeout(() => {
                firstPaintReady = true;
                hydrateStaticCss();
                for (const image of pendingImages) scheduleOriginal(image);
                pendingImages.clear();
                for (const element of pendingBackgrounds) hydrateDynamicBackground(element);
                pendingBackgrounds.clear();
            }, 80);
        };
        if (document.readyState === 'complete') upgradeAfterInitialPaint();
        else window.addEventListener('load', upgradeAfterInitialPaint, { once: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start, { once: true });
    } else start();
}
