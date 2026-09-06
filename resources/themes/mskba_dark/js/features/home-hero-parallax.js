const PARALLAX_SPEED_RATIO = 2.25;
const PARALLAX_COMPENSATION = 1 - (1 / PARALLAX_SPEED_RATIO);

function initHomeHeroParallax() {
    const hero = document.querySelector('.home-welcome');
    const imageLayer = hero?.querySelector('.home-welcome__image');

    if (!hero || !imageLayer) {
        return;
    }

    let frameRequested = false;

    const render = () => {
        frameRequested = false;

        const heroRect = hero.getBoundingClientRect();
        const travelled = Math.min(
            Math.max(-heroRect.top, 0),
            heroRect.height,
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

    window.addEventListener('scroll', requestRender, { passive: true });
    window.addEventListener('resize', requestRender, { passive: true });

    requestRender();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initHomeHeroParallax, { once: true });
} else {
    initHomeHeroParallax();
}
