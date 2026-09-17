const FIXED_SHOW_DELAY = 100;
const HOME_HERO_FIXED_OFFSET = 100;
const ANCHOR_SCROLL_GAP = 16;

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

    const getAnchorTarget = (hash) => {
        if (!hash || hash === '#') {
            return null;
        }

        try {
            return document.getElementById(decodeURIComponent(hash.slice(1)));
        } catch (_) {
            return null;
        }
    };

    const scrollToAnchor = (hash, behavior = 'auto') => {
        const target = getAnchorTarget(hash);

        if (!target) {
            return false;
        }

        syncHeaderHeight();

        const top = target.getBoundingClientRect().top
            + window.scrollY
            - headerHeight
            - ANCHOR_SCROLL_GAP;

        window.scrollTo({
            top: Math.max(0, top),
            behavior,
        });

        return true;
    };

    const correctCurrentAnchor = () => {
        if (!window.location.hash) {
            return;
        }

        window.requestAnimationFrame(() => {
            window.requestAnimationFrame(() => {
                scrollToAnchor(window.location.hash);
            });
        });
    };

    const handleAnchorClick = (event) => {
        if (
            event.defaultPrevented
            || event.button !== 0
            || event.metaKey
            || event.ctrlKey
            || event.shiftKey
            || event.altKey
        ) {
            return;
        }

        const source = event.target instanceof Element ? event.target : null;
        const link = source?.closest('a[href]');

        if (!link || (link.target && link.target !== '_self') || link.hasAttribute('download')) {
            return;
        }

        let url;

        try {
            url = new URL(link.href, window.location.href);
        } catch (_) {
            return;
        }

        if (
            url.origin !== window.location.origin
            || url.pathname !== window.location.pathname
            || url.search !== window.location.search
            || !url.hash
            || !getAnchorTarget(url.hash)
        ) {
            return;
        }

        event.preventDefault();

        const nextUrl = `${url.pathname}${url.search}${url.hash}`;
        const currentUrl = `${window.location.pathname}${window.location.search}${window.location.hash}`;

        if (nextUrl !== currentUrl) {
            window.history.pushState(null, '', nextUrl);
        }

        scrollToAnchor(url.hash);
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
    correctCurrentAnchor();

    window.addEventListener('load', () => {
        syncHeaderHeight();
        syncStickyState();
        correctCurrentAnchor();
    }, { once: true });

    window.addEventListener('resize', () => {
        syncHeaderHeight();
        syncStickyState();
    });

    window.addEventListener('scroll', requestStickyStateSync, { passive: true });
    window.addEventListener('hashchange', () => scrollToAnchor(window.location.hash));
    document.addEventListener('click', handleAnchorClick);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initStickyHeader, { once: true });
} else {
    initStickyHeader();
}
