/*
 * Progressive Site Media image enhancement. The server emits data-site-media-lqip
 * only when the derivative exists. Otherwise the normal image is untouched.
 * Full-size <img src> remains a working no-JavaScript fallback.
 */
function progressiveMediaImages() {
    document.querySelectorAll('img[data-site-media-lqip]').forEach(image => {
        if (image.dataset.siteLqipBound === '1') return;
        image.dataset.siteLqipBound = '1';

        const mobile = window.matchMedia('(max-width: 640px)').matches
            && image.closest('picture')?.querySelector('source[media*="640px"]');
        const previewUrl = mobile
            ? image.dataset.siteMediaLqipMobile
            : image.dataset.siteMediaLqip;
        if (!previewUrl || (image.complete && image.naturalWidth > 0)) return;

        let preview = null;
        const picture = image.closest('picture');
        if (picture) {
            picture.classList.add('has-site-lqip');
            picture.style.backgroundImage = 'url("' + previewUrl.replaceAll('"', '%22') + '")';
            picture.style.backgroundSize = 'cover';
            picture.style.backgroundPosition = 'center';
        } else {
            preview = image.cloneNode(false);
            preview.removeAttribute('id');
            preview.removeAttribute('data-site-media-lqip');
            preview.removeAttribute('data-site-media-lqip-mobile');
            preview.removeAttribute('data-motion');
            preview.removeAttribute('srcset');
            preview.src = previewUrl;
            preview.alt = '';
            preview.setAttribute('aria-hidden', 'true');
            preview.classList.add('site-lqip-preview');
            preview.classList.remove('site-lqip-full', 'site-lqip-ready');
            // Do not force below-the-fold slides to eager-load.
            preview.loading = image.loading;
            image.before(preview);
        }
        image.classList.add('site-lqip-full');

        const finish = () => {
            // Full image is decoded before transition to prevent white flashes.
            const reveal = () => {
                image.classList.add('site-lqip-ready');
                picture?.classList.add('site-lqip-ready');
                if (preview) {
                    preview.classList.add('site-lqip-fading');
                    preview.addEventListener('transitionend', () => preview.remove(), { once: true });
                    // Reduced motion generates no transitionend.
                    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) preview.remove();
                }
            };
            if (typeof image.decode === 'function') image.decode().then(reveal, reveal);
            else reveal();
        };
        if (image.complete && image.naturalWidth > 0) finish();
        else image.addEventListener('load', finish, { once: true });
    });
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', progressiveMediaImages, { once: true });
else progressiveMediaImages();
