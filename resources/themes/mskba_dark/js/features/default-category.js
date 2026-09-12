import '../../css/default-category.css';

const SEARCH_DEBOUNCE_MS = 450;

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-default-category]').forEach(initDefaultCategory);
});

function initDefaultCategory(root) {
    const search = root.querySelector('[data-default-category-search]');
    const viewMenuToggle = root.querySelector('[data-default-category-view-menu-toggle]');
    const viewMenu = root.querySelector('[data-default-category-view-menu]');
    const viewIcon = root.querySelector('[data-default-category-view-icon]');
    const viewOptions = Array.from(root.querySelectorAll('[data-default-category-view-option]'));
    let searchTimer = null;

    search?.addEventListener('input', () => {
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(() => search.form?.requestSubmit(), SEARCH_DEBOUNCE_MS);
    });

    search?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            window.clearTimeout(searchTimer);
        }
    });

    viewMenuToggle?.addEventListener('click', (event) => {
        event.stopPropagation();
        const open = viewMenu?.hidden ?? true;
        if (viewMenu) viewMenu.hidden = !open;
        viewMenuToggle.setAttribute('aria-expanded', String(open));
    });

    viewOptions.forEach((option) => {
        option.addEventListener('click', () => {
            const view = option.dataset.defaultCategoryViewOption;
            if (!view) return;

            setView(root, view, viewOptions, viewIcon);
            if (viewMenu) viewMenu.hidden = true;
            viewMenuToggle?.setAttribute('aria-expanded', 'false');
        });
    });

    document.addEventListener('click', (event) => {
        if (!viewMenu || viewMenu.hidden || event.target.closest('[data-default-category-view-switcher]')) return;
        viewMenu.hidden = true;
        viewMenuToggle?.setAttribute('aria-expanded', 'false');
    });

    root.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape' || !viewMenu || viewMenu.hidden) return;
        viewMenu.hidden = true;
        viewMenuToggle?.setAttribute('aria-expanded', 'false');
        viewMenuToggle?.focus();
    });
}

function setView(root, view, viewOptions, viewIcon) {
    root.dataset.defaultCategoryView = view;

    root.querySelectorAll('[data-default-category-results]').forEach((result) => {
        result.hidden = result.dataset.defaultCategoryResults !== view;
    });

    viewOptions.forEach((option) => {
        option.classList.toggle('is-active', option.dataset.defaultCategoryViewOption === view);
    });

    if (viewIcon) {
        viewIcon.classList.toggle('ti-layout-grid', view === 'cards');
        viewIcon.classList.toggle('ti-list', view === 'list');
    }

    document.querySelectorAll('[data-default-category-view-input]').forEach((input) => {
        input.value = view === 'cards' ? '' : view;
        input.disabled = view === 'cards';
    });

    const url = new URL(window.location.href);
    if (view === 'cards') {
        url.searchParams.delete('view');
    } else {
        url.searchParams.set('view', view);
    }
    window.history.replaceState({}, '', url);

    root.dispatchEvent(new CustomEvent('default-category:viewchange', {
        bubbles: true,
        detail: { view },
    }));
}
