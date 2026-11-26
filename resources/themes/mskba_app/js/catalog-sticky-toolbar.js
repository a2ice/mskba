// Match the sticky-state behaviour of the player workspace tabs.
const toolbar = document.querySelector('.panel.app-category-toolbar--compact');

if (toolbar) {
    const controller = new AbortController();
    const { signal } = controller;
    let pending = null;
    let followFrame = null;

    const updateSticky = () => {
        pending = null;
        const style = window.getComputedStyle(toolbar);
        const top = Number.parseFloat(style.top);
        const rect = toolbar.getBoundingClientRect();
        toolbar.classList.toggle('is-stuck',
            style.position === 'sticky' && Number.isFinite(top)
            && toolbar.getClientRects().length > 0
            && window.scrollY > 0 && Math.abs(rect.top - top) <= 2);
    };
    const scheduleSticky = () => {
        if (pending === null) pending = window.requestAnimationFrame(updateSticky);
    };

    window.addEventListener('scroll', scheduleSticky, { passive: true, signal });
    window.addEventListener('resize', scheduleSticky, { passive: true, signal });
    window.addEventListener('pageshow', scheduleSticky, { signal });
    window.addEventListener('load', scheduleSticky, { once: true, signal });
    toolbar.addEventListener('transitionend', scheduleSticky, { signal });
    window.addEventListener('mskba:header-visibility', () => {
        const until = performance.now() + 340;
        if (followFrame !== null) window.cancelAnimationFrame(followFrame);
        const follow = () => {
            scheduleSticky();
            followFrame = performance.now() < until ? window.requestAnimationFrame(follow) : null;
        };
        followFrame = window.requestAnimationFrame(follow);
    }, { signal });
    if ('ResizeObserver' in window) {
        const observer = new ResizeObserver(scheduleSticky);
        observer.observe(toolbar);
        signal.addEventListener('abort', () => observer.disconnect(), { once: true });
    }
    document.fonts?.ready?.then(() => { if (!signal.aborted) scheduleSticky(); });
    scheduleSticky();

    if (import.meta.hot) import.meta.hot.dispose(() => {
        controller.abort();
        if (pending !== null) window.cancelAnimationFrame(pending);
        if (followFrame !== null) window.cancelAnimationFrame(followFrame);
    });
}
