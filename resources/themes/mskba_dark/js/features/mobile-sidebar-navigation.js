const mobileViewport = window.matchMedia('(max-width: 768px)');

function setNavigationToggleState(isOpen) {
    document.querySelectorAll('[data-nav-toggle]').forEach((toggle) => {
        toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        toggle.setAttribute('aria-label', isOpen ? 'Закрыть основное меню' : 'Открыть основное меню');
    });
}

function initMobileSidebarNavigation() {
    const navigationSwitcher = document.querySelector('[data-mobile-nav-switcher]');
    const sidebar = document.querySelector('[data-mobile-section-sidebar]');
    const sidebarSlot = document.querySelector('[data-mobile-nav-sidebar-slot]');
    const mainSection = navigationSwitcher?.querySelector('[data-mobile-nav-section="main"]');
    const sidebarSection = navigationSwitcher?.querySelector('[data-mobile-nav-section="sidebar"]');
    const sidebarTab = navigationSwitcher?.querySelector('[data-mobile-nav-sidebar-tab]');

    if (!navigationSwitcher || !mainSection || !sidebarSection || !sidebarSlot || !sidebarTab) {
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
        sidebarTab.hidden = !isMobile || !hasSidebar;

        navigationSwitcher.querySelectorAll('[data-mobile-nav-section]').forEach((section) => {
            const name = section.dataset.mobileNavSection;
            const toggle = navigationSwitcher.querySelector(`[data-mobile-nav-section-toggle="${name}"]`);
            const panel = section.querySelector('[data-mobile-nav-section-panel]');
            const isActive = !isMobile || name === activeSection;

            toggle?.setAttribute('aria-selected', isActive ? 'true' : 'false');
            toggle?.setAttribute('tabindex', isActive ? '0' : '-1');

            if (panel) {
                panel.hidden = !isActive;
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
        const titleTarget = navigationSwitcher.querySelector('[data-mobile-nav-sidebar-title]');

        if (sidebarTitle && titleTarget) {
            titleTarget.textContent = sidebarTitle.replace(/^Навигация\s+/iu, 'Меню ');
        }

        document.body.classList.add('has-mobile-section-sidebar');
    }

    document.body.classList.add('mobile-sidebar-navigation-ready');

    navigationSwitcher.addEventListener('click', (event) => {
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

    navigationSwitcher.addEventListener('keydown', (event) => {
        const currentTab = event.target.closest('[role="tab"]');

        if (!currentTab || !mobileViewport.matches || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
            return;
        }

        event.preventDefault();

        const availableTabs = Array.from(navigationSwitcher.querySelectorAll('[role="tab"]'))
            .filter((tab) => !tab.hidden);
        const currentIndex = availableTabs.indexOf(currentTab);
        const requestedIndex = event.key === 'Home'
            ? 0
            : event.key === 'End'
                ? availableTabs.length - 1
                : (currentIndex + (event.key === 'ArrowRight' ? 1 : -1) + availableTabs.length) % availableTabs.length;
        const requestedTab = availableTabs[requestedIndex];

        activeSection = requestedTab.dataset.mobileNavSectionToggle;
        renderSections();
        requestedTab.focus();
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
