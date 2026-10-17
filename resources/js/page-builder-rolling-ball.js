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
// Keep the gentle stop of smoothstep, but give the ball nonzero motion
// from the *first* scroll pixel (smoothstep alone has zero initial velocity).
const rollingBallTravelEase = value => {
    const remaining = 1 - value;
    return rollingBallSmooth(value) + .6 * value * remaining * remaining;
};
// The ball now completes the entire first-scene path in 70% of the
// formerly required scroll distance, then remains attached to the hero.
const rollingBallTimelineFactor = .7;
const rollingBallSpriteFrames = 16;
const rollingBallAssetRevision = 'photo-20261017-v1';
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

    let apparentPathDistance = 0;
    let startRadius = 1;
    let endRadius = 1;
    let roundedTurns = 1;
    let maxPhysicalAngle = 1;
    let start = 0;
    let distance = 270;
    let target = 0;
    let displayed = 0;
    let raf = 0;
    let lastTime = 0;
    let initialized = false;
    let activeProfile = rollingBallProfiles.mobile;

    function paint(progress) {
        const p = rollingBallReducedMotion.matches ? 1 : progress;
        const travel = rollingBallTravelEase(p);
        // Both axes use the same progress: geometrically a straight line.
        const x = activeProfile.from[0] + (activeProfile.to[0] - activeProfile.from[0]) * travel;
        const y = activeProfile.from[1] + (activeProfile.to[1] - activeProfile.from[1]) * travel;
        const light = rollingBallSmooth(travel);
        const angle = rollingBallAngularTravel(apparentPathDistance, startRadius, endRadius, travel);
        // Nearest complete number of revolutions guarantees frame 0 on landing.
        const framesTravelled = maxPhysicalAngle > 0
            ? angle / maxPhysicalAngle * roundedTurns * rollingBallSpriteFrames
            : 0;
        const frame = p >= 1 ? 0 : Math.floor(framesTravelled + 1e-8) % rollingBallSpriteFrames;

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
        apparentPathDistance = Math.hypot(dx, dy);
        startRadius = activeProfile.tileSize * activeProfile.startScale / 2;
        endRadius = activeProfile.tileSize * activeProfile.endScale / 2;
        maxPhysicalAngle = rollingBallAngularTravel(apparentPathDistance, startRadius, endRadius, 1);
        roundedTurns = Math.max(1, Math.round(maxPhysicalAngle / rollingBallTwoPi));
        // If Hero is already within the initial viewport (homepage), start
        // at scrollY=0 rather than waiting for a fixed header offset.
        // Below-the-fold scenes start when they approach the viewport.
        const heroPageTop = window.scrollY + rect.top;
        start = Math.max(0, heroPageTop - window.innerHeight * .8);
        distance = Math.min(360, Math.max(270, rect.height * .48)) * rollingBallTimelineFactor;
        target = targetFromScroll();
        if (!initialized) {
            // No sudden jump on a restored scroll position after reload.
            displayed = target;
            initialized = true;
        }
        paint(displayed);
        queue();
    }

    function tick(now) {
        raf = 0;
        const elapsed = lastTime ? Math.min(64, now - lastTime) : 16;
        lastTime = now;
        displayed += (target - displayed) * (1 - Math.exp(-elapsed / 55));
        if (Math.abs(displayed - target) < .0003) displayed = target;
        paint(displayed);
        if (displayed !== target) raf = window.requestAnimationFrame(tick);
        else lastTime = 0;
    }

    function queue() {
        if (!initialized) return;
        target = targetFromScroll();
        if (!raf && Math.abs(displayed - target) > .0003) {
            raf = window.requestAnimationFrame(tick);
        }
    }

    window.addEventListener('scroll', queue, { passive: true });
    window.addEventListener('resize', measure, { passive: true });
    rollingBallReducedMotion.addEventListener?.('change', () => paint(displayed));
    if ('ResizeObserver' in window) {
        new ResizeObserver(measure).observe(slider);
    }
    measure();
}
document.querySelectorAll('.mskba-rolling-ball').forEach(initRollingBall);
