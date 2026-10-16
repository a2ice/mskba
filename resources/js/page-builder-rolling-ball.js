// TEMP (TODO): make scroll-ball parameters editable by superadmins with
// validated trusted settings, not arbitrary scripts in Page Builder.
// Independent of slide activation and navigation; opt-in via page markup.
const rollingBallReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
const rollingBallPaths = {
    mobile: 'M 235 405 C 430 440 785 510 850 615 C 900 735 600 920 205 1000',
    tablet: 'M 315 425 C 545 440 840 485 885 575 C 935 720 520 915 160 1000',
    desktop: 'M 475 440 C 640 450 900 490 945 585 C 985 745 530 925 130 1000',
};
const rollingBallClamp = (n, lo, hi) => Math.max(lo, Math.min(hi, n));
const rollingBallSmooth = n => n * n * (3 - 2 * n);

function initRollingBall(root) {
    if (root.dataset.rollingBallReady === '1') return;

    const scene = root.closest('.mskba-hero-scene');
    const sprite = root.querySelector('img.mskba-rolling-ball__sprite');
    if (!scene || !sprite || !scene.querySelector('[data-mskba-hero-slider]')) return;
    root.dataset.rollingBallReady = '1';
    // Page Builder sanitizer forces lazy images; preload this scene asset.
    sprite.loading = 'eager';

    const visual = document.createElement('div');
    visual.className = 'mskba-rolling-ball__visual';
    const frame = document.createElement('span');
    frame.className = 'mskba-rolling-ball__frame';
    visual.append(frame);
    root.append(visual);

    // The sprite URL comes from a sanitized <img src> in Page Builder HTML.
    // No script content or image URL is injected by administrators.
    const ready = () => {
        if (sprite.naturalWidth !== 1024 || sprite.naturalHeight !== 1024) {
            // 16 frames in a fixed 4 x 4 sprite sheet, 256 x 256 per cell.
            return;
        }
        frame.style.backgroundImage = `url("${sprite.currentSrc || sprite.src}")`;
        measure();
    };
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    svg.append(path);
    let pathLength = 0;
    let start = 0;
    let distance = 270;
    let target = 0;
    let displayed = 0;
    let raf = 0;
    let lastTime = 0;
    let enabled = false;

    function measure() {
        const rect = scene.getBoundingClientRect();
        const band = rect.width <= 640 ? 'mobile' : rect.width <= 1050 ? 'tablet' : 'desktop';
        path.setAttribute('d', rollingBallPaths[band]);
        pathLength = path.getTotalLength();
        start = Math.max(0, window.scrollY + rect.top - 107);
        distance = Math.min(360, Math.max(270, rect.height * .48));
        enabled = true;
        queue();
    }

    function paint(progress) {
        const p = rollingBallReducedMotion.matches ? 1 : progress;
        const travel = rollingBallSmooth(p);
        const pt = path.getPointAtLength(pathLength * travel);
        const light = rollingBallSmooth(rollingBallClamp((travel - .02) / .9, 0, 1));
        const frameIndex = Math.round(travel * 16 * 6) % 16;
        const col = frameIndex % 4;
        const row = Math.floor(frameIndex / 4);

        root.style.left = (pt.x / 10).toFixed(3) + '%';
        root.style.top = (pt.y / 10).toFixed(3) + '%';
        root.style.opacity = p > .002 || rollingBallReducedMotion.matches ? '1' : '0';
        root.style.setProperty('--ball-scale', (0.75 + .25 * travel).toFixed(4));
        root.style.setProperty('--ball-brightness', (.07 + .95 * light).toFixed(4));
        root.style.setProperty('--ball-saturation', (.38 + .62 * light).toFixed(4));
        frame.style.backgroundPosition = `${col * 100 / 3}% ${row * 100 / 3}%`;
    }

    function tick(now) {
        raf = 0;
        const elapsed = lastTime ? Math.min(64, now - lastTime) : 16;
        lastTime = now;
        displayed += (target - displayed) * (1 - Math.exp(-elapsed / 55));
        if (Math.abs(displayed - target) < .0003) displayed = target;
        paint(displayed);
        if (displayed !== target) raf = requestAnimationFrame(tick);
        else lastTime = 0;
    }

    function queue() {
        if (!enabled) return;
        target = rollingBallClamp((window.scrollY - start) / distance, 0, 1);
        if (!raf) raf = requestAnimationFrame(tick);
    }

    window.addEventListener('scroll', queue, { passive: true });
    window.addEventListener('resize', measure, { passive: true });
    rollingBallReducedMotion.addEventListener?.('change', queue);
    if (sprite.complete) ready();
    else sprite.addEventListener('load', ready, { once: true });
}
document.querySelectorAll('.mskba-rolling-ball').forEach(initRollingBall);
