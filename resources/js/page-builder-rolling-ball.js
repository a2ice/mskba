// TEMP TODO: superadmin-configurable trusted effect, not arbitrary stored JS.
// Opt-in via .mskba-hero-scene and .mskba-rolling-ball. The ball is shared by
// all slides and lives inside the slider root for correct z-index interleaving.
const rollingBallReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
const rollingBallPaths = {
    mobile: 'M 235 405 C 430 440 785 510 850 615 C 900 735 600 920 205 1000',
    tablet: 'M 315 425 C 545 440 840 485 885 575 C 935 720 520 915 160 1000',
    desktop: 'M 475 440 C 640 450 900 490 945 585 C 985 745 530 925 130 1000',
};
const rollingBallClamp = (value, lo, hi) => Math.max(lo, Math.min(hi, value));
const rollingBallSmooth = value => value * value * (3 - 2 * value);

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
    if (sprite.complete) useSprite();
    else sprite.addEventListener('load', useSprite, { once: true });

    const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    let pathLength = 0;
    let start = 0;
    let distance = 270;
    let target = 0;
    let displayed = 0;
    let raf = 0;
    let lastTime = 0;
    let initialized = false;

    function paint(progress) {
        const p = rollingBallReducedMotion.matches ? 1 : progress;
        const travel = rollingBallSmooth(p);
        const point = path.getPointAtLength(pathLength * travel);
        const light = rollingBallSmooth(rollingBallClamp((travel - .02) / .9, 0, 1));
        const frame = Math.round(travel * 16 * 6) % 16;

        root.style.left = (point.x / 10).toFixed(3) + '%';
        root.style.top = (point.y / 10).toFixed(3) + '%';
        root.style.setProperty('--ball-scale', (0.75 + .25 * travel).toFixed(4));
        root.style.setProperty('--ball-brightness', (.07 + .95 * light).toFixed(4));
        root.style.setProperty('--ball-saturation', (.38 + .62 * light).toFixed(4));
        root.style.setProperty('--ball-frame-x', `${(frame % 4) * 100 / 3}%`);
        root.style.setProperty('--ball-frame-y', `${Math.floor(frame / 4) * 100 / 3}%`);
    }

    function targetFromScroll() {
        return rollingBallClamp((window.scrollY - start) / distance, 0, 1);
    }

    function measure() {
        const rect = slider.getBoundingClientRect();
        const band = rect.width <= 640 ? 'mobile' : rect.width <= 1050 ? 'tablet' : 'desktop';
        path.setAttribute('d', rollingBallPaths[band]);
        pathLength = path.getTotalLength();
        start = Math.max(0, window.scrollY + rect.top - 107);
        distance = Math.min(360, Math.max(270, rect.height * .48));
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
