(() => {
    'use strict';
    function applyOverlayCss() {
        document.querySelectorAll('.entity-public-banner--background[data-banner-overlay-css]')
            .forEach((banner) => {
                // Never interpolate arbitrary backoffice CSS into an inline style.
                banner.style.setProperty(
                    '--entity-banner-overlay-css',
                    banner.dataset.bannerOverlayCss ?? 'rgba(0,0,0,.5)',
                );
            });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', applyOverlayCss, { once: true });
    } else {
        applyOverlayCss();
    }
})();
