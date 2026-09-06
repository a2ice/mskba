const FIXED_SHOW_DELAY = 100;
const HOME_HERO_FIXED_OFFSET = 100;

function initStickyHeader() {
    const header = document.querySelector('.site-header');
    const wrapper = header?.querySelector('.header-wrapper');
    const homeHero = document.body.classList.contains('main')
        ? document.querySelector('.home-welcome')
        : null;

    if (!header || !wrapper) {
        return;
    }

    let headerHeight = 0;
    let homeFixedThreshold = null;
    let revealTimer = null;
    let ticking = false;

    const getFixedThreshold = () => homeFixedThreshold ?? headerHeight;

    const syncFixedThreshold = () => {
        if (!homeHero) {
            homeFixedThreshold = null;
            return;
        }

        const heroTop = homeHero.getBoundingClientRect().top + window.scrollY;
        const heroHeight = homeHero.getBoundingClientRect().height || homeHero.offsetHeight || 0;

        homeFixedThreshold = Math.max(
            headerHeight,
            Math.round(heroTop + heroHeight - HOME_HERO_FIXED_OFFSET),
        );
    };

    const syncHeaderHeight = () => {
        const measuredHeight = Math.ceil(wrapper.getBoundingClientRect().height || wrapper.offsetHeight || 0);

        if (measuredHeight <= 0) {
            return;
        }

        headerHeight = measuredHeight;
        header.style.height = `${headerHeight}px`;
        document.documentElement.style.setProperty('--site-header-height', `${headerHeight}px`);
        syncFixedThreshold();
    };

    const hideFixedHeader = () => {
        if (revealTimer !== null) {
            window.clearTimeout(revealTimer);
            revealTimer = null;
        }

        header.classList.remove('is-fixed-shown', 'is-fixed');
    };

    const showFixedHeader = () => {
        if (header.classList.contains('is-fixed')) {
            return;
        }

        header.classList.add('is-fixed');
        header.classList.remove('is-fixed-shown');

        revealTimer = window.setTimeout(() => {
            revealTimer = null;

            if (window.scrollY > getFixedThreshold() && header.classList.contains('is-fixed')) {
                header.classList.add('is-fixed-shown');
            }
        }, FIXED_SHOW_DELAY);
    };

    const syncStickyState = () => {
        ticking = false;

        if (window.scrollY > getFixedThreshold()) {
            showFixedHeader();
        } else {
            hideFixedHeader();
        }
    };

    const requestStickyStateSync = () => {
        if (ticking) {
            return;
        }

        ticking = true;
        window.requestAnimationFrame(syncStickyState);
    };

    syncHeaderHeight();
    syncStickyState();

    window.addEventListener('load', () => {
        syncHeaderHeight();
        syncStickyState();
    }, { once: true });

    window.addEventListener('resize', () => {
        syncHeaderHeight();
        syncStickyState();
    });

    window.addEventListener('scroll', requestStickyStateSync, { passive: true });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initStickyHeader, { once: true });
} else {
    initStickyHeader();
}
