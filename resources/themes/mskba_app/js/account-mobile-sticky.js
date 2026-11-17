// The account navigation stays in the page flow, sticking under the context bar.
// Scroll changes only its visual density; native <details> and desktop remain intact.
const layout = document.querySelector('.app-account-layout');

if (layout) {
    const breakpoint = window.matchMedia('(max-width: 900px)');
    let stickyBoundary = 108;

    function update() {
        const aside = layout.querySelector('.app-account-layout__aside');
        if (!aside) return;

        if (!breakpoint.matches) {
            aside.classList.remove('is-stuck');
            return;
        }

        // Read the natural, non-sticky position from the layout. Changes to the
        // compact aside's height cannot move this origin or oscillate the state.
        const bounds = layout.getBoundingClientRect();
        aside.classList.toggle('is-stuck',
            bounds.top <= stickyBoundary && bounds.bottom > stickyBoundary + 52);
    }

    function refreshBoundary() {
        const styles = getComputedStyle(document.documentElement);
        const liveOffset = parseFloat(styles.getPropertyValue('--app-sticky-offset'));
        stickyBoundary = Number.isFinite(liveOffset)
            ? liveOffset
            : (parseFloat(styles.getPropertyValue('--header-height')) || 64)
                + (parseFloat(styles.getPropertyValue('--context-bar-height')) || 44);
        update();
    }

    // Direct updates avoid stale compact state in throttled/background tabs.
    // There is one inexpensive bounding-rect read and no network or reflow loop.
    window.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', refreshBoundary, { passive: true });
    window.addEventListener('mskba:header-visibility', refreshBoundary);
    breakpoint.addEventListener('change', refreshBoundary);
    document.addEventListener('mskba:account-sidebar-updated', update);
    refreshBoundary();
}
