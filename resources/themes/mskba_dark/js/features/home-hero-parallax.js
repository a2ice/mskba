const HOME_HERO_PARALLAX_LAYERS = [
    { name: 'sky', src: '/images/home/hero-parallax/home-hero-sky.png', depth: 1 },
    { name: 'city', src: '/images/home/hero-parallax/home-hero-city.png', depth: 0.78 },
    { name: 'kremlin', src: '/images/home/hero-parallax/home-hero-kremlin.png', depth: 0.58 },
    { name: 'tree-light', src: '/images/home/hero-parallax/home-hero-tree-light.png', depth: 0.35 },
    { name: 'court', src: '/images/home/hero-parallax/home-hero-court.png', depth: 0.15 },
];

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

    const layerImages = HOME_HERO_PARALLAX_LAYERS.map((layer, index) => {
        const image = document.createElement('img');
        image.className = `home-welcome__parallax-layer home-welcome__parallax-layer--${layer.name}`;
        image.src = layer.src;
        image.alt = '';
        image.decoding = 'async';
        image.loading = 'eager';
        image.dataset.parallaxDepth = String(layer.depth);

        if (index === 0 || layer.name === 'court') {
            image.fetchPriority = 'high';
        }

        stack.appendChild(image);
        return image;
    });

    imageLayer.appendChild(stack);

    Promise.all(layerImages.map(preloadLayer))
        .then(() => imageLayer.classList.add('is-parallax-ready'))
        .catch(() => {
            stack.remove();
            imageLayer.classList.remove('home-welcome__image--parallax');
            fallbackImage.classList.remove('home-welcome__parallax-fallback');
        });

    let frameRequested = false;

    const render = () => {
        frameRequested = false;

        if (!stack.isConnected) {
            return;
        }

        const heroRect = hero.getBoundingClientRect();
        const travelled = Math.min(Math.max(-heroRect.top, 0), heroRect.height);
        const progress = heroRect.height > 0 ? travelled / heroRect.height : 0;
        const mobile = window.innerWidth <= 768;
        const maxShift = mobile
            ? Math.min(heroRect.height * 0.12, 100)
            : Math.min(heroRect.height * 0.18, 150);

        layerImages.forEach((layer) => {
            const depth = Number.parseFloat(layer.dataset.parallaxDepth || '0');
            const offset = progress * maxShift * depth;
            layer.style.setProperty('--home-hero-parallax-y', `${offset.toFixed(2)}px`);
        });
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
