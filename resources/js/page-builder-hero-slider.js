// First-party interactive component. Page Builder content is data-only; never eval it.
const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

function initHeroSlider(root) {
    if (root.dataset.initialized === '1') return;
    root.dataset.initialized = '1';

    const slides = [...root.querySelectorAll('[data-hero-slide]')];
    if (!slides.length) return;

    const dots = [...root.querySelectorAll('[data-hero-go]')];
    const autoplay = root.dataset.autoplay === '1';
    const interval = Math.max(4000, Math.min(30000, Number(root.dataset.interval) || 7000));
    let active = 0;
    let timer = null;
    let isHovered = false;
    let hasFocus = false;
    let isVisible = true;

    const stop = () => {
        if (timer !== null) window.clearTimeout(timer);
        timer = null;
    };
    const schedule = () => {
        stop();
        if (!autoplay || slides.length < 2 || reducedMotion.matches
            || isHovered || hasFocus || !isVisible || document.hidden) return;
        timer = window.setTimeout(() => show(active + 1), interval);
    };

    function show(requested, moveFocus = false) {
        const next = (requested + slides.length) % slides.length;
        if (next === active && root.dataset.ready === '1') {
            schedule();
            return;
        }
        slides.forEach((slide, index) => {
            const selected = index === next;
            slide.classList.remove('is-active', 'is-animating');
            slide.hidden = !selected;
            slide.inert = !selected;
            slide.setAttribute('aria-hidden', String(!selected));
            if (selected) slide.classList.add('is-active');
        });
        active = next;
        dots.forEach((dot, index) => {
            if (index === active) dot.setAttribute('aria-current', 'true');
            else dot.removeAttribute('aria-current');
        });

        const current = slides[active];
        if (!reducedMotion.matches) {
            // Animation restarts when a slide is selected, including when looped.
            void current.offsetWidth;
            current.classList.add('is-animating');
        }
        root.dataset.ready = '1';
        if (moveFocus && dots[active]) dots[active].focus({ preventScroll: true });
        schedule();
    }

    root.querySelector('[data-hero-prev]')?.addEventListener('click', () => show(active - 1));
    root.querySelector('[data-hero-next]')?.addEventListener('click', () => show(active + 1));
    dots.forEach((dot, index) =>
        dot.addEventListener('click', () => show(index)));

    root.addEventListener('keydown', event => {
        if (event.target instanceof HTMLElement && event.target.closest('a, input, textarea')) return;
        if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') {
            event.preventDefault();
            show(active + (event.key === 'ArrowRight' ? 1 : -1));
        }
    });
    let touchX = null;
    root.addEventListener('touchstart', event => {
        touchX = event.touches.length === 1 ? event.touches[0].clientX : null;
    }, { passive: true });
    root.addEventListener('touchend', event => {
        if (touchX === null || event.changedTouches.length !== 1) return;
        const difference = event.changedTouches[0].clientX - touchX;
        touchX = null;
        if (Math.abs(difference) >= 52) show(active + (difference < 0 ? 1 : -1));
    }, { passive: true });
    root.addEventListener('touchcancel', () => { touchX = null; });
    root.addEventListener('mouseenter', () => { isHovered = true; stop(); });
    root.addEventListener('mouseleave', () => { isHovered = false; schedule(); });
    root.addEventListener('focusin', () => { hasFocus = true; stop(); });
    root.addEventListener('focusout', event => {
        if (event.relatedTarget instanceof Node && root.contains(event.relatedTarget)) return;
        hasFocus = false;
        schedule();
    });
    document.addEventListener('visibilitychange', schedule);
    reducedMotion.addEventListener?.('change', () => {
        if (reducedMotion.matches) slides[active].classList.remove('is-animating');
        schedule();
    });

    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver(entries => {
            isVisible = entries[0]?.isIntersecting ?? false;
            schedule();
        }, { threshold: .2 });
        observer.observe(root);
    }
    show(0);
}

document.querySelectorAll('[data-mskba-hero-slider]').forEach(initHeroSlider);
