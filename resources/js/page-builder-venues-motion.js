// Scenes use a reachable page-scroll timeline, not the fraction of an
// abstract viewport + section interval that may never reach its endpoint.
// No scroll hijacking, timers, or time-based easing: native scroll is the clock.
const venuesMotionReduced = window.matchMedia('(prefers-reduced-motion: reduce)');
const venuesMotionClamp = (value, min, max) => Math.max(min, Math.min(max, value));
const venuesMotionSmooth = p => p * p * (3 - 2 * p);

/**
 * Scroll progress is guaranteed to reach one if the user reaches the bottom
 * of the page, even when the Venues section itself is shorter than a screen.
 * Both the entrance and the finish use actual reachable scroll coordinates.
 */
function venuesMotionProgress(scrollTop, sectionTop, sectionHeight, viewportHeight, maxScroll) {
    if (maxScroll <= 0) return 1;
    const start = venuesMotionClamp(
        sectionTop - viewportHeight * .82, 0, maxScroll,
    );
    const idealEnd = sectionTop + sectionHeight * .82 - viewportHeight * .30;
    const end = venuesMotionClamp(Math.max(start + 1, idealEnd), start, maxScroll);
    if (end <= start) return scrollTop >= start ? 1 : 0;
    return venuesMotionClamp((scrollTop - start) / (end - start), 0, 1);
}

function initVenuesMotion(story) {
    if (story.dataset.venuesMotionReady === '1') return;
    const venues = story.querySelector('[data-mskba-venues-scene]');
    const ghost = venues?.querySelector('.mskba-venues-scene__ghost');
    const highlights = venues?.querySelector('.mskba-venues-scene__highlights');
    if (!venues || !ghost || !highlights) return;

    story.dataset.venuesMotionReady = '1';
    const layers = [...story.querySelectorAll('.mskba-home-story__art-layer')];
    // Backdrop stays registered to the story. Intermediate layers and player
    // move progressively more, but all keep the same scaled PNG canvas.
    const travelPx = [0, 44, 74, 142]; // distant fence, hoop and athlete's jump
    let pendingFrame = false;
    let measuredWidth = 0;
    let stableViewportHeight = window.innerHeight;

    function paint() {
        pendingFrame = false;
        const scrollTop = window.scrollY;
        const rect = venues.getBoundingClientRect();
        const storyWidth = story.getBoundingClientRect().width;
        // Safari toolbar resize during a flick must not alter progress.
        // Orientation / breakpoint changes are detected through width.
        if (Math.abs(storyWidth - measuredWidth) >= 1) {
            measuredWidth = storyWidth;
            stableViewportHeight = window.innerHeight;
        }
        const scrollHeight = document.documentElement.scrollHeight;
        const maxScroll = Math.max(0, scrollHeight - stableViewportHeight);
        const sectionPageTop = rect.top + scrollTop;
        const progress = venuesMotionProgress(
            scrollTop, sectionPageTop, rect.height, stableViewportHeight, maxScroll,
        );
        const noMotion = venuesMotionReduced.matches;
        const amplitude = storyWidth <= 640 ? .4 : storyWidth <= 1050 ? .65 : 1;

        layers.forEach((layer, index) => {
            const shift = noMotion ? 0 : -progress * travelPx[index] * amplitude;
            layer.style.setProperty('--venues-parallax-shift', shift.toFixed(2) + 'px');
        });
        const playerShift = noMotion ? 0 : -progress * travelPx[3] * amplitude;
        story.style.setProperty('--venues-player-shift', playerShift.toFixed(2) + 'px');

        // From right outside the canvas into the exact authored full-width
        // position; every page reaches the destination before its bottom.
        const enter = noMotion ? 1
            : venuesMotionSmooth(venuesMotionClamp((progress - .06) / .60, 0, 1));
        const initialOffset = Math.min(storyWidth * .35, 560);
        story.style.setProperty('--venues-ghost-shift',
            ((1 - enter) * initialOffset).toFixed(2) + 'px');

        // Reveal at the midpoint of the *reachable* second-scene timeline.
        const reveal = noMotion ? 1
            : venuesMotionSmooth(venuesMotionClamp((progress - .46) / .22, 0, 1));
        story.style.setProperty('--venues-highlights-opacity', reveal.toFixed(3));
        story.style.setProperty('--venues-highlights-lift',
            ((1 - reveal) * 18).toFixed(2) + 'px');
        story.dataset.venuesProgress = progress.toFixed(3);
    }

    function schedule() {
        if (pendingFrame) return;
        pendingFrame = true;
        window.requestAnimationFrame(paint);
    }

    window.addEventListener('scroll', schedule, { passive: true });
    window.addEventListener('resize', schedule, { passive: true });
    venuesMotionReduced.addEventListener?.('change', schedule);
    // Check after images/load as total document height can change.
    window.addEventListener('load', schedule, { once: true });
    paint();
}

document.querySelectorAll('[data-mskba-home-story]').forEach(initVenuesMotion);
