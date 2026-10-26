/*
 * Automatically enhance stored Page Builder HTML and CSS backgrounds only when
 * the server confirms a current Site Media thumbnail exists. The original
 * <img src> and all editor-managed CSS/HTML are left intact.
 */
const canvas = document.querySelector('.mskba-page-builder');
const manifestNode = document.querySelector('[data-page-builder-lqip-manifest]');

if (canvas && manifestNode) {
    let previews = {};
    try {
        previews = JSON.parse(manifestNode.textContent || '{}');
    } catch {
        previews = {};
    }

    const uuidFrom = value => {
        if (!value) return null;
        try {
            const parsed = new URL(value, window.location.href);
            if (parsed.origin !== window.location.origin) return null;
            const match = parsed.pathname.match(/^\/media\/site\/([0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12})$/i);
            return match?.[1].toLowerCase() || null;
        } catch {
            return null;
        }
    };
    const thumbnail = url => previews[uuidFrom(url)] || null;
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function decoded(image, finished) {
        const reveal = () => {
            if (typeof image.decode === 'function') image.decode().then(finished, finished);
            else finished();
        };
        if (image.complete && image.naturalWidth > 0) reveal();
        else {
            image.addEventListener('load', reveal, { once: true });
            image.addEventListener('error', finished, { once: true });
        }
    }

    function imagePreview(image) {
        // Hero Slider and the reusable Blade component already have their own
        // LQIP lifecycle. Never duplicate their thumbnails or fade effects.
        if (image.hasAttribute('data-site-media-lqip') || image.closest('[data-site-media-progressive]')) return;
        const previewUrl = thumbnail(image.currentSrc || image.src);
        if (!previewUrl || (image.complete && image.naturalWidth > 0)) return;

        const render = () => {
            if (!image.isConnected || image.complete && image.naturalWidth > 0) return;
            const rectangle = image.getBoundingClientRect();
            const parent = image.parentElement;
            if (!parent || rectangle.width < 4 || rectangle.height < 4) return;

            // An absolutely positioned sibling does not change the editor's
            // layout, unlike adding an HTML wrapper around a positioned image.
            const originalPosition = window.getComputedStyle(image);
            const overlay = document.createElement('img');
            overlay.src = previewUrl;
            overlay.alt = '';
            overlay.loading = image.loading === 'lazy' ? 'lazy' : 'eager';
            overlay.setAttribute('aria-hidden', 'true');
            overlay.className = 'pb-site-lqip-image-overlay';

            // offsetLeft/top are relative to image.offsetParent. Insert the
            // overlay next to it to share the same containing block.
            const offsetParent = image.offsetParent;
            if (!offsetParent || !offsetParent.contains(parent)) return;
            overlay.style.position = 'absolute';
            overlay.style.left = image.offsetLeft + 'px';
            overlay.style.top = image.offsetTop + 'px';
            overlay.style.width = image.offsetWidth + 'px';
            overlay.style.height = image.offsetHeight + 'px';
            overlay.style.objectFit = originalPosition.objectFit;
            overlay.style.objectPosition = originalPosition.objectPosition;
            overlay.style.borderRadius = originalPosition.borderRadius;
            overlay.style.transform = originalPosition.transform;
            overlay.style.transformOrigin = originalPosition.transformOrigin;
            overlay.style.zIndex = originalPosition.zIndex;
            image.before(overlay);

            let done = false;
            decoded(image, () => {
                if (done) return;
                done = true;
                overlay.classList.add('is-lqip-replaced');
                if (reducedMotion) overlay.remove();
                else overlay.addEventListener('transitionend', () => overlay.remove(), { once: true });
            });
        };
        // Wait for the CSS-driven dimensions to settle before positioning.
        window.requestAnimationFrame(render);
    }

    function backgroundPreview(element) {
        if (element.classList.contains('pb-site-lqip-background') || element.closest('[data-site-media-progressive]')) return;
        const computed = window.getComputedStyle(element);
        const originalBackground = computed.backgroundImage;
        if (!originalBackground || originalBackground === 'none') return;

        const imageUrls = [...originalBackground.matchAll(/url\(\s*["']?([^"'()]+)["']?\s*\)/gi)]
            .map(match => match[1]);
        // Avoid pretending that one thumbnail represents several distinct
        // photos or a tiled Yandex map / complex background composition.
        if (imageUrls.length !== 1) return;
        const fullUrl = imageUrls[0];
        const previewUrl = thumbnail(fullUrl);
        if (!previewUrl) return;

        const rect = element.getBoundingClientRect();
        if (rect.width < 4 || rect.height < 4) return;
        const placeholder = document.createElement('div');
        placeholder.className = 'pb-site-lqip-background';
        placeholder.setAttribute('aria-hidden', 'true');
        placeholder.style.backgroundImage = originalBackground.replace(
            fullUrl, new URL(previewUrl, window.location.href).href,
        );
        placeholder.style.backgroundSize = computed.backgroundSize;
        placeholder.style.backgroundPosition = computed.backgroundPosition;
        placeholder.style.backgroundRepeat = computed.backgroundRepeat;
        placeholder.style.backgroundClip = computed.backgroundClip;
        placeholder.style.backgroundOrigin = computed.backgroundOrigin;
        placeholder.style.borderRadius = computed.borderRadius;

        // Negative z-index inside an isolated stacking context keeps the
        // miniature behind all text/buttons, but above the original background.
        element.classList.add('has-pb-site-lqip-background');
        if (computed.position === 'static') element.classList.add('pb-site-lqip-containing-block');
        element.prepend(placeholder);
        const loader = new Image();
        let finished = false;
        const remove = () => {
            if (finished) return;
            finished = true;
            placeholder.classList.add('is-lqip-replaced');
            if (reducedMotion) placeholder.remove();
            else placeholder.addEventListener('transitionend', () => placeholder.remove(), { once: true });
        };
        loader.onload = () => {
            if (typeof loader.decode === 'function') loader.decode().then(remove, remove);
            else remove();
        };
        loader.onerror = remove;
        loader.src = fullUrl;
        if (loader.complete && loader.naturalWidth > 0) {
            if (typeof loader.decode === 'function') loader.decode().then(remove, remove);
            else remove();
        }
    }

    function start() {
        // Keep the DOM walk scoped to editor canvas; no global image scanning.
        canvas.querySelectorAll('img').forEach(imagePreview);
        for (const element of [canvas, ...canvas.querySelectorAll('*')]) {
            if (element.tagName === 'SCRIPT' || element.tagName === 'IMG') continue;
            backgroundPreview(element);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start, { once: true });
    } else {
        start();
    }
}
