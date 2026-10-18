// The second scene shares native scroll with Ball Director, never hijacks it.
// All four PNG canvases remain registered; each receives a controlled Y offset.
const venuesMotionReduced = window.matchMedia('(prefers-reduced-motion: reduce)');
const venuesMotionClamp = (value, min, max) => Math.max(min, Math.min(max, value));
const venuesMotionSmooth = p => p * p * (3 - 2 * p);

function initVenuesMotion(story) {
    if (story.dataset.venuesMotionReady === '1') return;
    const venues = story.querySelector('[data-mskba-venues-scene]');
    const ghost = venues?.querySelector('.mskba-venues-scene__ghost');
    const highlights = venues?.querySelector('.mskba-venues-scene__highlights');
    if (!venues || !ghost || !highlights) return;

    story.dataset.venuesMotionReady = '1';
    const layers = [...story.querySelectorAll('.mskba-home-story__art-layer')];
    const travelPx = [14, 38, 57, 74]; // furthest depth -> nearest foreground
    let pendingFrame = false;
    let lastWidth = 0;

    function paint() {
        pendingFrame = false;
        const rect = venues.getBoundingClientRect();
        const viewportHeight = window.innerHeight;
        // 0: venues starts to enter the viewport; 1: venues finishes passing.
        const progress = venuesMotionClamp(
            (viewportHeight - rect.top) / Math.max(1, viewportHeight + rect.height), 0, 1,
        );
        const noMotion = venuesMotionReduced.matches;
        const depth = noMotion ? 0 : progress;
        const width = story.getBoundingClientRect().width;

        // Mild movement on narrow devices: depth is still visible but does not
        // magnify the foreground or displace the ball anchor abruptly.
        if (Math.abs(width - lastWidth) > 0.5) lastWidth = width;
        const amplitude = width <= 640 ? .32 : width <= 1050 ? .58 : 1;
        layers.forEach((layer, index) => {
            const shift = -depth * (travelPx[index] ?? 0) * amplitude;
            layer.style.setProperty('--venues-parallax-shift', shift.toFixed(2) + 'px');
        });

        // The decorative ghost enters from the right; it is always bounded
        // inside the shared scene and does not change document width.
        const ghostProgress = noMotion ? 1
            : venuesMotionSmooth(venuesMotionClamp((progress - .06) / .43, 0, 1));
        const startOffset = Math.min(width * .32, 440);
        story.style.setProperty('--venues-ghost-shift',
            ((1 - ghostProgress) * startOffset).toFixed(2) + 'px');

        // Four highlights enter together around the midpoint of the section,
        // then remain shown. No JS or reduced-motion: content is visible.
        const reveal = noMotion ? 1
            : venuesMotionSmooth(venuesMotionClamp((progress - .48) / .13, 0, 1));
        story.style.setProperty('--venues-highlights-opacity', reveal.toFixed(3));
        story.style.setProperty('--venues-highlights-lift',
            ((1 - reveal) * 22).toFixed(2) + 'px');
    }

    function schedule() {
        if (pendingFrame) return;
        pendingFrame = true;
        requestAnimationFrame(paint);
    }

    window.addEventListener('scroll', schedule, { passive: true });
    window.addEventListener('resize', schedule, { passive: true });
    venuesMotionReduced.addEventListener?.('change', schedule);
    // JS is a progressive enhancement, and the initial state is based on the
    // actual scroll position (including restored browser history positions).
    paint();
}

document.querySelectorAll('[data-mskba-home-story]').forEach(initVenuesMotion);
