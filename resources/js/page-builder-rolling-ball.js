// TEMP TODO: superadmin-configurable trusted effect, not arbitrary stored JS.
// Opt-in via .mskba-hero-scene and .mskba-rolling-ball. The ball is shared by
// all slides and lives inside the slider root for correct z-index interleaving.
const rollingBallReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
// First-scene profiles use the *rendered hero width*, not the device name.
// The 0..1000 coordinate system preserves the same responsive DOM wrapper.
// A future section-to-section director can own this ball without changing
// the sprite renderer or reintroducing slide-specific layers.
const rollingBallProfiles = {
    mobile: {
        from: [850, 405], to: [205, 1000],
        startScale: .75, endScale: 1, tileSize: 122,
    },
    tablet: {
        from: [785, 425], to: [290, 1000],
        startScale: .43, endScale: 1, tileSize: 165,
    },
    desktop: {
        from: [745, 440], to: [315, 1000],
        startScale: .22, endScale: 1, tileSize: 195,
    },
};
// Old brightness at scroll 0 was .07 (almost entirely black). Its halfway
// timeline level was ~.59, so use that as the floor: low-lit, never a silhouette.
// No opacity interpolation or frame blending is involved.
const rollingBallLighting = {
    startBrightness: .57,
    endBrightness: 1.02,
    startSaturation: .74,
    endSaturation: 1,
};
const rollingBallClamp = (value, lo, hi) => Math.max(lo, Math.min(hi, value));
const rollingBallSmooth = value => value * value * (3 - 2 * value);
// For this first scene, use strictly linear scroll progress for both
// translation and photographed frame selection. Native scroll is the clock;
// no easing or time-based follower that continues to move after scrolling stops.
// The ball now completes the entire first-scene path in 70% of the
// formerly required scroll distance, then remains attached to the hero.
const rollingBallTimelineFactor = .7;
const rollingBallSpriteFrames = 16;
const rollingBallAssetRevision = 'photo-20261017-centered-v2';
const rollingBallTwoPi = 2 * Math.PI;

// Approximate no-slip physical rolling: angular displacement = integral(ds/R)
// along the straight, screen-projected path as the apparent radius grows.
function rollingBallAngularTravel(distance, startRadius, endRadius, progress) {
    const t = rollingBallClamp(progress, 0, 1);
    const delta = endRadius - startRadius;
    if (Math.abs(delta) < 1e-6) return distance * t / startRadius;
    return distance * Math.log((startRadius + delta * t) / startRadius) / delta;
}

function initRollingBall(root) {
    if (root.dataset.rollingBallReady === '1') return;
    const scene = root.closest('.mskba-hero-scene');
    const slider = scene?.querySelector('[data-mskba-hero-slider]');
    const sprite = root.querySelector('img.mskba-rolling-ball__sprite');
    if (!scene || !slider || !sprite) return;
    root.dataset.rollingBallReady = '1';

    // Move into the slider, NOT any slide. Background and scrim are below z=3;
    // copy, links and pagination are above it. Switching slides does not reset
    // the ball's coordinates or rotation.
    slider.append(root);
    sprite.loading = 'eager';

    // The CSS pseudo-element paints frame zero synchronously, before JS/image
    // load; loaded images may replace only the sprite URL, never opacity.
    const useSprite = () => {
        if (sprite.naturalWidth !== 1024 || sprite.naturalHeight !== 1024) return;
        const url = sprite.currentSrc || sprite.src;
        if (url && /^https?:\/\//.test(url)) {
            root.style.setProperty('--ball-image', `url("${url.replaceAll('"', '%22')}")`);
        }
    };
    // Bypass cached old WebP while preserving Filament custom asset URLs.
    const originalUrl = new URL(sprite.getAttribute('src') || sprite.src, location.href);
    if (originalUrl.pathname === '/images/ball-16.webp') {
        originalUrl.searchParams.set('v', rollingBallAssetRevision);
        sprite.src = originalUrl.toString();
    }
    if (sprite.complete) useSprite();
    else sprite.addEventListener('load', useSprite, { once: true });

    let revolutions = 1;
    let start = 0;
    let distance = 270;
    let activeProfile = rollingBallProfiles.mobile;

    function paint(progress) {
        const p = rollingBallReducedMotion.matches ? 1 : progress;
        const travel = p;
        // Both axes use the same progress: geometrically a straight line.
        const x = activeProfile.from[0] + (activeProfile.to[0] - activeProfile.from[0]) * travel;
        const y = activeProfile.from[1] + (activeProfile.to[1] - activeProfile.from[1]) * travel;
        const light = rollingBallSmooth(travel);
        // Fixed step length in real scroll pixels: equal scroll increments
        // advance equal frame counts. No angular acceleration during scale-up.
        // At the exact landing, wrap cleanly to the first photographed frame.
        const framesTravelled = Math.floor(p * revolutions * rollingBallSpriteFrames);
        const frame = p >= 1 ? 0 : framesTravelled % rollingBallSpriteFrames;

        root.style.left = (x / 10).toFixed(3) + '%';
        root.style.top = (y / 10).toFixed(3) + '%';
        root.style.setProperty('--ball-scale',
            (activeProfile.startScale + (activeProfile.endScale - activeProfile.startScale) * travel).toFixed(4));
        root.style.setProperty('--ball-brightness',
            (rollingBallLighting.startBrightness
                + (rollingBallLighting.endBrightness - rollingBallLighting.startBrightness) * light).toFixed(4));
        root.style.setProperty('--ball-saturation',
            (rollingBallLighting.startSaturation
                + (rollingBallLighting.endSaturation - rollingBallLighting.startSaturation) * light).toFixed(4));
        root.style.setProperty('--ball-frame-x', `${(frame % 4) * 100 / 3}%`);
        root.style.setProperty('--ball-frame-y', `${Math.floor(frame / 4) * 100 / 3}%`);
    }

    function targetFromScroll() {
        return rollingBallClamp((window.scrollY - start) / distance, 0, 1);
    }

    function measure() {
        const rect = slider.getBoundingClientRect();
        const band = rect.width <= 640 ? 'mobile' : rect.width <= 1050 ? 'tablet' : 'desktop';
        activeProfile = rollingBallProfiles[band];
        const dx = (activeProfile.to[0] - activeProfile.from[0]) / 1000 * rect.width;
        const dy = (activeProfile.to[1] - activeProfile.from[1]) / 1000 * rect.height;
        const apparentPathDistance = Math.hypot(dx, dy);
        const startRadius = activeProfile.tileSize * activeProfile.startScale / 2;
        const endRadius = activeProfile.tileSize * activeProfile.endScale / 2;
        const totalAngle = rollingBallAngularTravel(apparentPathDistance, startRadius, endRadius, 1);
        // Choose one or more whole turns from the approximate travel geometry;
        // distribute all of their frames UNIFORMLY over the scroll timeline.
        revolutions = Math.max(1, Math.round(totalAngle / rollingBallTwoPi));
        // If Hero is already within the initial viewport (homepage), start
        // at scrollY=0 rather than waiting for a fixed header offset.
        // Below-the-fold scenes start when they approach the viewport.
        const heroPageTop = window.scrollY + rect.top;
        start = Math.max(0, heroPageTop - window.innerHeight * .8);
        distance = Math.min(360, Math.max(270, rect.height * .48)) * rollingBallTimelineFactor;
        // A restored scroll position must immediately reproduce exactly
        // the same ball pose; there is no time-based catch-up.
        paint(targetFromScroll());
    }

    // The hero and the ball share the browser's native scroll clock. Once the
    // target clamps to 1, every following scroll paints the identical landed
    // frame and coordinates (the DOM continues moving with the page).
    window.addEventListener('scroll', () => paint(targetFromScroll()), { passive: true });
    window.addEventListener('resize', measure, { passive: true });
    rollingBallReducedMotion.addEventListener?.('change', () => paint(targetFromScroll()));
    if ('ResizeObserver' in window) {
        new ResizeObserver(measure).observe(slider);
    }
    measure();
}
document.querySelectorAll('.mskba-rolling-ball').forEach(initRollingBall);
