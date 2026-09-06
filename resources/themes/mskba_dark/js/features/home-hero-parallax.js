const PARALLAX_SPEED_RATIO = 1.5;
const PARALLAX_COMPENSATION = 1 - (1 / PARALLAX_SPEED_RATIO);

function initHomeHeroParallax() {
    const hero = document.querySelector('.home-welcome');
    const imageLayer = hero?.querySelector('.home-welcome__image');

    if (!hero || !imageLayer) {
        return;
    }

    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    let heroTop = 0;
    let heroHeight = 0;
    let frameRequested = false;

    const reset = () => {
        imageLayer.style.transform = '';
        imageLayer.style.willChange = '';
    };

    const render = () => {
        frameRequested = false;

        if (reducedMotion.matches) {
            reset();
            return;
        }

        const travelled = Math.min(
            Math.max(window.scrollY - heroTop, 0),
            heroHeight,
        );
        const offset = travelled * PARALLAX_COMPENSATION;

        imageLayer.style.willChange = 'transform';
        imageLayer.style.transform = `translate3d(0, ${offset.toFixed(2)}px, 0)`;
    };

    const requestRender = () => {
        if (frameRequested) {
            return;
        }

        frameRequested = true;
        window.requestAnimationFrame(render);
    };

    const measure = () => {
        const rect = hero.getBoundingClientRect();
        heroTop = window.scrollY + rect.top;
        heroHeight = hero.offsetHeight;
        requestRender();
    };

    window.addEventListener('scroll', requestRender, { passive: true });
    window.addEventListener('resize', measure, { passive: true });

    const handleMotionPreference = () => {
        if (reducedMotion.matches) {
            reset();
            return;
        }

        measure();
    };

    if (typeof reducedMotion.addEventListener === 'function') {
        reducedMotion.addEventListener('change', handleMotionPreference);
    } else if (typeof reducedMotion.addListener === 'function') {
        reducedMotion.addListener(handleMotionPreference);
    }

    measure();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initHomeHeroParallax, { once: true });
} else {
    initHomeHeroParallax();
}
