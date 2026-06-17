const header = document.querySelector('[data-mskba-header]');
const menuToggle = document.querySelector('[data-mskba-menu-toggle]');
const menu = document.querySelector('#mskba-main-nav');

if (header && menuToggle && menu) {
    const closeMenu = () => {
        header.classList.remove('is-menu-open');
        menuToggle.setAttribute('aria-expanded', 'false');
    };

    menuToggle.addEventListener('click', () => {
        const isOpen = header.classList.toggle('is-menu-open');

        menuToggle.setAttribute('aria-expanded', String(isOpen));
    });

    menu.addEventListener('click', (event) => {
        if (event.target instanceof HTMLAnchorElement) {
            closeMenu();
        }
    });

    window.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeMenu();
        }
    });
}
