const SCROLL_INTENT_DISTANCE = 6;
const TOP_EDGE_REVEAL_ZONE = 18;
const ANCHOR_SCROLL_GAP = 16;

function initStickyHeader() {
    const header = document.querySelector('.site-header');
    const wrapper = header?.querySelector('.header-wrapper');
    const contextBar = document.querySelector('[data-context-submenu]');

    if (!header || !wrapper) {
        return;
    }

    let headerHeight = 0;
    let contextBarHeight = 0;
    let scrollReference = window.scrollY;
    let scrollingUp = false;
    let pointerReveal = false;
    let ticking = false;

    const isNavigationOpen = () => document.body.classList.contains('nav-shown');

    const syncOffsets = (headerShown) => {
        // Keep the legacy token stable for layouts that use it for sizing/padding.
        // Sticky surfaces instead use the height actually covering the viewport.
        const visibleHeaderHeight = headerShown ? headerHeight : 0;
        const stickyOffset = contextBarHeight + visibleHeaderHeight;
        const root = document.documentElement;

        root.style.setProperty('--site-header-height', `${headerHeight + contextBarHeight}px`);
        root.style.setProperty('--site-primary-header-height', `${headerHeight}px`);
        root.style.setProperty('--site-context-bar-height', `${contextBarHeight}px`);
        root.style.setProperty('--site-primary-visible-height', `${visibleHeaderHeight}px`);
        root.style.setProperty('--site-sticky-offset', `${stickyOffset}px`);

        const scrollPadding = `${stickyOffset + ANCHOR_SCROLL_GAP}px`;
        root.style.scrollPaddingTop = scrollPadding;
        document.body.style.scrollPaddingTop = scrollPadding;
    };

    const syncHeaderHeight = () => {
        const measuredHeight = Math.ceil(wrapper.offsetHeight || wrapper.getBoundingClientRect().height || 0);

        if (measuredHeight > 0) {
            headerHeight = measuredHeight;
            header.style.height = `${headerHeight}px`;
        }

        contextBarHeight = Math.ceil(contextBar?.offsetHeight || 0);
    };

    const syncStickyState = () => {
        const scrollY = window.scrollY;
        // Once revealed, keep the fixed header until reaching the page top;
        // switching to its in-flow counterpart sooner would cause a jump.
        const isFixed = scrollY > headerHeight
            || (header.classList.contains('is-fixed') && scrollY > 1);

        if (!isFixed) {
            scrollingUp = false;
            pointerReveal = false;
        }

        const isShown = isFixed && (
            scrollingUp
            || pointerReveal
            || isNavigationOpen()
            || header.contains(document.activeElement)
        );

        header.classList.toggle('is-fixed', isFixed);
        header.classList.toggle('is-fixed-shown', isShown);
        syncOffsets(isShown);
    };

    const syncScrollDirection = () => {
        ticking = false;

        const scrollY = window.scrollY;
        const distance = scrollY - scrollReference;

        if (Math.abs(distance) >= SCROLL_INTENT_DISTANCE) {
            scrollingUp = distance < 0;

            if (distance > 0) {
                pointerReveal = false;
            }

            scrollReference = scrollY;
        }

        if (scrollY <= 1) {
            scrollReference = 0;
            scrollingUp = false;
            pointerReveal = false;
        }

        syncStickyState();
    };

    const requestScrollSync = () => {
        if (ticking) {
            return;
        }

        ticking = true;
        window.requestAnimationFrame(syncScrollDirection);
    };

    const handlePointerMove = (event) => {
        if (event.pointerType && event.pointerType !== 'mouse') {
            return;
        }

        const isAtEdge = event.clientY >= 0 && event.clientY <= TOP_EDGE_REVEAL_ZONE;
        const isInRevealedNavigation = pointerReveal
            && header.classList.contains('is-fixed-shown')
            && event.clientY >= 0
            && event.clientY <= headerHeight + contextBarHeight;
        const nextReveal = window.scrollY > headerHeight && (isAtEdge || isInRevealedNavigation);

        if (nextReveal !== pointerReveal) {
            pointerReveal = nextReveal;
            syncStickyState();
        }
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

        // Reserve the full navigation stack: scrolling upward can reveal the
        // primary header before the target reaches the viewport.
        const top = target.getBoundingClientRect().top
            + window.scrollY
            - headerHeight
            - contextBarHeight
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
            window.requestAnimationFrame(() => scrollToAnchor(window.location.hash));
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

    if ('ResizeObserver' in window) {
        const observer = new ResizeObserver(() => {
            syncHeaderHeight();
            syncStickyState();
        });

        observer.observe(wrapper);
        if (contextBar) {
            observer.observe(contextBar);
        }
    }

    // The mobile menu may open while the primary header is hidden.
    new MutationObserver(syncStickyState).observe(document.body, {
        attributes: true,
        attributeFilter: ['class'],
    });

    header.addEventListener('focusin', syncStickyState);
    header.addEventListener('focusout', () => window.requestAnimationFrame(syncStickyState));

    window.addEventListener('scroll', requestScrollSync, { passive: true });
    window.addEventListener('pointermove', handlePointerMove, { passive: true });
    document.addEventListener('pointerleave', () => {
        pointerReveal = false;
        syncStickyState();
    });
    window.addEventListener('hashchange', () => scrollToAnchor(window.location.hash));
    document.addEventListener('click', handleAnchorClick);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initStickyHeader, { once: true });
} else {
    initStickyHeader();
}
