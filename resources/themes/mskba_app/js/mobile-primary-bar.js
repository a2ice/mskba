// The mobile dock is theme-owned and remains independent of the legacy nav-shown handler.
const mobileDock = document.querySelector('[data-app-mobile-primary]');

if (mobileDock) {
    const panel = mobileDock.querySelector('[data-app-mobile-primary-panel]');
    const toggle = mobileDock.querySelector('[data-app-mobile-primary-toggle]');
    const closeButton = mobileDock.querySelector('[data-app-mobile-primary-close]');
    const backdrop = document.querySelector('[data-app-mobile-primary-backdrop]');
    const mobileQuery = window.matchMedia('(max-width: 768px)');

    const setOpen = (open, restoreFocus = false) => {
        if (!panel || !toggle || !backdrop) return;
        panel.hidden = !open;
        backdrop.hidden = !open;
        document.body.classList.toggle('app-mobile-primary-open', open);
        toggle.setAttribute('aria-expanded', String(open));
        toggle.setAttribute('aria-label', open ? 'Закрыть меню' : 'Открыть меню');

        if (open) {
            closeButton?.focus({ preventScroll: true });
        } else if (restoreFocus && mobileQuery.matches) {
            toggle.focus({ preventScroll: true });
        }
    };

    toggle?.addEventListener('click', () => {
        setOpen(panel.hidden);
    });
    closeButton?.addEventListener('click', () => setOpen(false, true));
    backdrop?.addEventListener('click', () => setOpen(false, true));

    panel?.querySelectorAll('a[href]').forEach((link) => {
        link.addEventListener('click', () => setOpen(false));
    });

    document.addEventListener('keydown', (event) => {
        if (!panel || panel.hidden) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            setOpen(false, true);
            return;
        }
        if (event.key !== 'Tab') return;

        // Keep keyboard focus inside the open modal drawer.
        const focusable = [...panel.querySelectorAll('a[href], button:not([disabled]), summary')]
            .filter((node) => node.getClientRects().length > 0);
        if (!focusable.length) return;

        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });

    mobileQuery.addEventListener('change', () => {
        if (!mobileQuery.matches) setOpen(false);
    });
}
