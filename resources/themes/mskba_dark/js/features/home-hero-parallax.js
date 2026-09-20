const HOME_HERO_PARALLAX_LAYERS = [
    { name: 'sky', src: '/images/home/hero-parallax/home-hero-sky.png', compensation: 0.79 },
    { name: 'city', src: '/images/home/hero-parallax/home-hero-city.png', compensation: 0.67 },
    { name: 'kremlin', src: '/images/home/hero-parallax/home-hero-kremlin.png', compensation: 0.56 },
    { name: 'tree-light', src: '/images/home/hero-parallax/home-hero-tree-light.png', compensation: 0.45 },
    { name: 'court', src: '/images/home/hero-parallax/home-hero-court.png', compensation: 0.37 },
];

const PARALLAX_SMOOTHING_MS = 45;
const PARALLAX_EPSILON = 0.08;

function preloadLayer(image) {
    return new Promise((resolve, reject) => {
        if (image.complete) {
            if (image.naturalWidth > 0) {
                resolve();
            } else {
                reject(new Error(`Failed to load ${image.src}`));
            }
            return;
        }

        image.addEventListener('load', resolve, { once: true });
        image.addEventListener('error', reject, { once: true });
    });
}

function supportsNativeScrollParallax() {
    return !document.body.classList.contains('telegram-mini-app')
        && typeof CSS !== 'undefined'
        && CSS.supports?.('animation-timeline', 'scroll(root block)')
        && CSS.supports?.('animation-range-start', '1px')
        && CSS.supports?.('animation-range-end', '2px');
}

function initHomeHeroParallax() {
    const hero = document.querySelector('.home-welcome');
    const imageLayer = hero?.querySelector('.home-welcome__image');
    const fallbackImage = imageLayer?.querySelector(':scope > img');

    if (!hero || !imageLayer || !fallbackImage || imageLayer.querySelector('.home-welcome__parallax')) {
        return;
    }

    imageLayer.style.removeProperty('transform');
    imageLayer.style.removeProperty('will-change');
    imageLayer.classList.add('home-welcome__image--parallax');
    fallbackImage.classList.add('home-welcome__parallax-fallback');

    const stack = document.createElement('div');
    stack.className = 'home-welcome__parallax';
    stack.setAttribute('aria-hidden', 'true');

    const layers = HOME_HERO_PARALLAX_LAYERS.map((layer, index) => {
        const image = document.createElement('img');
        image.className = `home-welcome__parallax-layer home-welcome__parallax-layer--${layer.name}`;
        image.src = layer.src;
        image.alt = '';
        image.decoding = 'async';
        image.loading = 'eager';

        if (index === 0 || layer.name === 'court') {
            image.fetchPriority = 'high';
        }

        stack.appendChild(image);

        return {
            image,
            compensation: layer.compensation,
        };
    });

    imageLayer.appendChild(stack);

    Promise.all(layers.map(({ image }) => preloadLayer(image)))
        .then(() => imageLayer.classList.add('is-parallax-ready'))
        .catch(() => {
            stack.remove();
            imageLayer.classList.remove(
                'home-welcome__image--parallax',
                'home-welcome__image--native-scroll',
            );
            fallbackImage.classList.remove('home-welcome__parallax-fallback');
        });

    const useNativeScrollTimeline = supportsNativeScrollParallax();

    let heroTop = 0;
    let heroHeight = 0;
    let scale = 1.12;
    let targetTravelled = 0;
    let currentTravelled = 0;
    let frameId = null;
    let previousFrameTime = 0;
    let geometryReady = false;

    const clampTravelled = (value) => Math.min(Math.max(value, 0), heroHeight);

    const syncTarget = () => {
        targetTravelled = clampTravelled(window.scrollY - heroTop);
    };

    const paint = (travelled) => {
        layers.forEach(({ image, compensation }) => {
            const offset = travelled * compensation;
            image.style.transform = `translate3d(var(--home-parallax-x, 0px), ${offset.toFixed(2)}px, 0) scale(${scale})`;
        });
    };

    const syncNativeTimelineGeometry = () => {
        imageLayer.style.setProperty('--home-parallax-range-start', `${heroTop}px`);
        imageLayer.style.setProperty('--home-parallax-range-end', `${heroTop + heroHeight}px`);
        imageLayer.style.setProperty('--home-parallax-scale', String(scale));

        layers.forEach(({ image, compensation }) => {
            image.style.setProperty('--home-parallax-end-y', `${(heroHeight * compensation).toFixed(2)}px`);
            image.style.removeProperty('transform');
        });

        imageLayer.classList.add('home-welcome__image--native-scroll');
    };

    const render = (timestamp) => {
        frameId = null;

        if (!stack.isConnected) {
            return;
        }

        const delta = targetTravelled - currentTravelled;

        if (Math.abs(delta) <= PARALLAX_EPSILON) {
            currentTravelled = targetTravelled;
            paint(currentTravelled);
            previousFrameTime = 0;
            return;
        }

        const elapsed = previousFrameTime > 0
            ? Math.min(timestamp - previousFrameTime, 64)
            : 16.67;
        previousFrameTime = timestamp;

        const smoothing = 1 - Math.exp(-elapsed / PARALLAX_SMOOTHING_MS);
        currentTravelled += delta * smoothing;
        paint(currentTravelled);

        frameId = window.requestAnimationFrame(render);
    };

    const requestRender = () => {
        syncTarget();

        if (frameId === null) {
            frameId = window.requestAnimationFrame(render);
        }
    };

    const syncGeometry = ({ snap = false } = {}) => {
        if (!stack.isConnected) {
            return;
        }

        const rect = hero.getBoundingClientRect();
        heroTop = rect.top + window.scrollY;
        heroHeight = rect.height || hero.offsetHeight || 0;
        scale = window.innerWidth <= 768 ? 1.18 : 1.12;

        if (useNativeScrollTimeline) {
            syncNativeTimelineGeometry();
            geometryReady = true;
            return;
        }

        syncTarget();

        if (snap || !geometryReady) {
            currentTravelled = targetTravelled;
            paint(currentTravelled);
            geometryReady = true;
        } else {
            requestRender();
        }
    };

    if (!useNativeScrollTimeline) {
        window.addEventListener('scroll', requestRender, { passive: true });
    }

    window.addEventListener('resize', () => syncGeometry(), { passive: true });
    window.addEventListener('load', () => syncGeometry({ snap: true }), { once: true });

    if ('ResizeObserver' in window) {
        const geometryObserver = new ResizeObserver(() => syncGeometry());
        geometryObserver.observe(hero);

        const header = document.querySelector('.site-header');
        if (header) {
            geometryObserver.observe(header);
        }
    }

    syncGeometry({ snap: true });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initHomeHeroParallax, { once: true });
} else {
    initHomeHeroParallax();
}
