// Small progressive enhancement for native <details> navigation.
// Keep independent of the legacy theme's DOM handlers and the future Vue bootstrap.
const controller = new AbortController();
const { signal } = controller;

function closeMenus(except = null) {
    document.querySelectorAll('[data-app-header] details[open]').forEach((menu) => {
        if (menu !== except && !menu.contains(except)) menu.open = false;
    });
}

document.addEventListener('pointerdown', (event) => {
    if (!event.target.closest('[data-app-header]')) closeMenus();
}, { signal });

document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    const opened = [...document.querySelectorAll('[data-app-header] details[open]')];
    if (!opened.length) return;
    const last = opened.at(-1);
    last.open = false;
    last.querySelector('summary')?.focus();
}, { signal });

document.querySelectorAll('[data-app-header] a').forEach((link) => {
    link.addEventListener('click', () => closeMenus(), { signal });
});

// Only one first-level dropdown should be open at a time.
document.querySelectorAll('.app-header-nav-group').forEach((menu) => {
    menu.addEventListener('toggle', () => {
        if (!menu.open) return;
        document.querySelectorAll('.app-header-nav-group[open]').forEach((other) => {
            if (other !== menu) other.open = false;
        });
    }, { signal });
});

if (import.meta.hot) import.meta.hot.dispose(() => controller.abort());


/* Primary navigation is allowed to scroll away while the contextual bar remains
 * sticky. Reveal on upward scroll or mouse entering the first 18 viewport pixels.
 * The shared offset drives the venue anchors/sidebar and account sticky UI. */
const siteHeader = document.querySelector('[data-app-header]');
if (siteHeader) {
    const contextBar = document.querySelector('[data-app-context-bar]');
    const TOP_EDGE_PX = 18;
    const DIRECTION_THRESHOLD_PX = 6;
    let headerHeight = siteHeader.offsetHeight;
    let contextHeight = contextBar?.offsetHeight || 0;
    let previousScrollY = Math.max(0, window.scrollY);
    let scrollDelta = 0;
    let scrollingUp = false;
    let pointerReveal = false;
    let previousState = '';
    let scrollFrame = null;

    const navigationOpen = () => Boolean(siteHeader.querySelector('details[open]'));

    const updateOffsets = (visible) => {
        const root = document.documentElement;
        const visibleHeight = visible ? headerHeight : 0;
        const stickyOffset = visibleHeight + contextHeight;
        const state = `${visibleHeight}:${contextHeight}`;

        if (state === previousState) return;
        previousState = state;

        root.style.setProperty('--app-visible-header-height', `${visibleHeight}px`);
        root.style.setProperty('--app-sticky-offset', `${stickyOffset}px`);
        window.dispatchEvent(new CustomEvent('mskba:header-visibility', {
            detail: { visible, stickyOffset, headerHeight, contextHeight },
        }));
    };

    const updateVisibility = () => {
        // Do not revoke the revealed/sticky state at scrollY <= headerHeight.
        // At that boundary sticky naturally rejoins the in-flow header at y=0.
        // Switching the class off there causes the visible 80px jump on scroll-up.
        // Keep the upward intent through the very top; the next downward scroll
        // will hide the header naturally.
        const reveal = scrollingUp
            || pointerReveal
            || navigationOpen()
            || siteHeader.contains(document.activeElement);

        siteHeader.classList.toggle('is-scroll-revealed', reveal);
        updateOffsets(reveal);
    };

    const updateDimensions = () => {
        headerHeight = Math.ceil(siteHeader.offsetHeight);
        contextHeight = Math.ceil(contextBar?.offsetHeight || 0);
        updateVisibility();
    };

    const onScroll = () => {
        scrollFrame = null;
        const current = Math.max(0, window.scrollY);
        const diff = current - previousScrollY;
        previousScrollY = current;

        if ((diff > 0 && scrollDelta < 0) || (diff < 0 && scrollDelta > 0)) {
            scrollDelta = 0;
        }

        scrollDelta += diff;

        if (Math.abs(scrollDelta) >= DIRECTION_THRESHOLD_PX) {
            scrollingUp = scrollDelta < 0;
            scrollDelta = 0;
            if (!scrollingUp) pointerReveal = false;
        }

        if (current <= 1) {
            // Preserve scrollingUp at the page origin. The normal-flow and
            // sticky top=0 positions coincide, so no handover is necessary.
            pointerReveal = false;
            scrollDelta = 0;
        }

        updateVisibility();
    };

    window.addEventListener('scroll', () => {
        if (scrollFrame === null) {
            scrollFrame = window.requestAnimationFrame(onScroll);
        }
    }, { passive: true, signal });

    window.addEventListener('pointermove', (event) => {
        if (event.pointerType !== 'mouse') return;

        const atEdge = event.clientY >= 0 && event.clientY <= TOP_EDGE_PX;
        const withinRevealedNavigation = pointerReveal
            && event.clientY >= 0
            && event.clientY <= Math.max(
                siteHeader.getBoundingClientRect().bottom,
                contextBar?.getBoundingClientRect().bottom || 0,
            );
        const reveal = window.scrollY > 1 && (atEdge || withinRevealedNavigation);

        if (reveal !== pointerReveal) {
            pointerReveal = reveal;
            updateVisibility();
        }
    }, { passive: true, signal });

    document.addEventListener('pointerleave', () => {
        if (!pointerReveal) return;
        pointerReveal = false;
        updateVisibility();
    }, { signal });
    siteHeader.addEventListener('toggle', updateVisibility, { capture: true, signal });
    siteHeader.addEventListener('focusin', updateVisibility, { signal });
    siteHeader.addEventListener('focusout', () => {
        window.requestAnimationFrame(updateVisibility);
    }, { signal });
    window.addEventListener('resize', updateDimensions, { passive: true, signal });

    if ('ResizeObserver' in window) {
        const observer = new ResizeObserver(updateDimensions);
        observer.observe(siteHeader);
        if (contextBar) observer.observe(contextBar);
        signal.addEventListener('abort', () => observer.disconnect(), { once: true });
    }

    updateDimensions();
}
