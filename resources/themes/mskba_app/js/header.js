// Small progressive enhancement for native <details> navigation.
// Keep independent of the legacy theme's DOM handlers and the future Vue bootstrap.
const controller = new AbortController();
const { signal } = controller;

function closeMenus(except = null) {
    document.querySelectorAll('[data-app-header] details[open]').forEach((menu) => {
        if (menu !== except && !menu.contains(except)) menu.open = false;
    });
}

document.addEventListener('pointerdown', (event) => {
    if (!event.target.closest('[data-app-header]')) closeMenus();
}, { signal });

document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    const opened = [...document.querySelectorAll('[data-app-header] details[open]')];
    if (!opened.length) return;
    const last = opened.at(-1);
    last.open = false;
    last.querySelector('summary')?.focus();
}, { signal });

document.querySelectorAll('[data-app-header] a').forEach((link) => {
    link.addEventListener('click', () => closeMenus(), { signal });
});

// Only one first-level dropdown should be open at a time.
document.querySelectorAll('.app-header-nav-group').forEach((menu) => {
    menu.addEventListener('toggle', () => {
        if (!menu.open) return;
        document.querySelectorAll('.app-header-nav-group[open]').forEach((other) => {
            if (other !== menu) other.open = false;
        });
    }, { signal });
});

if (import.meta.hot) import.meta.hot.dispose(() => controller.abort());
