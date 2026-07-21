const mobileViewport = window.matchMedia('(max-width: 768px)');

function setNavigationToggleState(isOpen) {
    document.querySelectorAll('[data-nav-toggle]').forEach((toggle) => {
        toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        toggle.setAttribute('aria-label', isOpen ? 'Закрыть основное меню' : 'Открыть основное меню');
    });
}

function initMobileSidebarNavigation() {
    const accordion = document.querySelector('[data-mobile-nav-accordion]');
    const sidebar = document.querySelector('[data-mobile-section-sidebar]');
    const sidebarSlot = document.querySelector('[data-mobile-nav-sidebar-slot]');
    const mainSection = accordion?.querySelector('[data-mobile-nav-section="main"]');
    const sidebarSection = accordion?.querySelector('[data-mobile-nav-section="sidebar"]');

    if (!accordion || !mainSection || !sidebarSection || !sidebarSlot) {
        return;
    }

    const hasSidebar = Boolean(sidebar && (
        sidebar.textContent.trim() !== ''
        || sidebar.querySelector('a, button, input, select, textarea, img')
    ));
    const originMarker = hasSidebar ? document.createComment('mobile-section-sidebar-origin') : null;
    let activeSection = hasSidebar ? 'sidebar' : 'main';

    if (sidebar && originMarker) {
        sidebar.before(originMarker);
    }

    const renderSections = () => {
        const isMobile = mobileViewport.matches;

        mainSection.hidden = false;
        sidebarSection.hidden = !isMobile || !hasSidebar;

        accordion.querySelectorAll('[data-mobile-nav-section]').forEach((section) => {
            const name = section.dataset.mobileNavSection;
            const toggle = section.querySelector('[data-mobile-nav-section-toggle]');
            const panel = section.querySelector('[data-mobile-nav-section-panel]');
            const isExpanded = !isMobile || name === activeSection;

            toggle?.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');

            if (panel) {
                panel.hidden = !isExpanded;
            }
        });
    };

    const syncSidebarPosition = () => {
        if (!hasSidebar || !sidebar || !originMarker) {
            renderSections();
            return;
        }

        if (mobileViewport.matches) {
            sidebarSlot.append(sidebar);
            sidebar.classList.add('is-mobile-nav-sidebar');
        } else if (originMarker.parentNode) {
            originMarker.parentNode.insertBefore(sidebar, originMarker.nextSibling);
            sidebar.classList.remove('is-mobile-nav-sidebar');
        }

        renderSections();
    };

    if (hasSidebar && sidebar) {
        const sidebarTitle = sidebar.dataset.mobileSectionSidebarTitle;
        const titleTarget = accordion.querySelector('[data-mobile-nav-sidebar-title]');

        if (sidebarTitle && titleTarget) {
            titleTarget.textContent = sidebarTitle;
        }

        document.body.classList.add('has-mobile-section-sidebar');
    }

    document.body.classList.add('mobile-sidebar-navigation-ready');

    accordion.addEventListener('click', (event) => {
        const toggle = event.target.closest('[data-mobile-nav-section-toggle]');

        if (!toggle || !mobileViewport.matches) {
            return;
        }

        const requestedSection = toggle.dataset.mobileNavSectionToggle;

        if (requestedSection === 'sidebar' && !hasSidebar) {
            return;
        }

        activeSection = requestedSection;
        renderSections();
    });

    sidebarSlot.addEventListener('click', (event) => {
        if (!mobileViewport.matches || !event.target.closest('a[href]')) {
            return;
        }

        document.body.classList.remove('nav-shown');
        setNavigationToggleState(false);
    });

    if (typeof mobileViewport.addEventListener === 'function') {
        mobileViewport.addEventListener('change', syncSidebarPosition);
    } else {
        mobileViewport.addListener(syncSidebarPosition);
    }

    syncSidebarPosition();
}

initMobileSidebarNavigation();
