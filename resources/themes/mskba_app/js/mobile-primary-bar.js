// Shared mobile dock: one overlay and one focus trap for Play, Menu and contextual filters.
const dock = document.querySelector('[data-app-mobile-primary]');

if (dock) {
    const backdrop = document.querySelector('[data-app-mobile-primary-backdrop]');
    const mobile = window.matchMedia('(max-width: 768px)');
    const contexts = {
        play: {
            panel: dock.querySelector('[data-app-mobile-primary-play-panel]'),
            toggle: dock.querySelector('[data-app-mobile-primary-play-toggle]'),
        },
        menu: {
            panel: dock.querySelector('[data-app-mobile-primary-panel]'),
            toggle: dock.querySelector('[data-app-mobile-primary-toggle]'),
        },
        filter: {
            panel: dock.querySelector('[data-app-mobile-primary-filter-panel]'),
            toggle: dock.querySelector('[data-app-mobile-primary-filter-toggle]'),
        },
    };
    const filterPanel = contexts.filter.panel;
    const filterHost = dock.querySelector('[data-context-filter-host]');
    const globalForm = dock.querySelector('[data-context-global-search]');
    const filterTitle = dock.querySelector('[data-app-mobile-primary-filter-title]');
    const filterLabel = dock.querySelector('[data-app-mobile-primary-filter-label]');
    const filterBadge = dock.querySelector('[data-app-mobile-primary-filter-badge]');
    const filterCount = dock.querySelector('[data-context-filter-count]');
    const filterExpand = dock.querySelector('[data-context-filter-expand]');
    const filterPreview = dock.querySelector('[data-context-filter-preview]');
    const quickSearch = dock.querySelector('[data-context-filter-quick-search]');
    const filterScroll = dock.querySelector('[data-context-filter-scroll]');
    const apply = dock.querySelector('[data-context-filter-apply]');
    const reset = dock.querySelector('[data-context-filter-reset]');
    const globalSearch = globalForm?.querySelector('[name="q"]');
    const origins = [
        { selector: '.app-category__mobile-filters .app-category__mobile-filters-body', title: 'Фильтры' },
        { selector: '.app-shop__mobile-filters .app-shop__mobile-filters-body', title: 'Фильтры товаров' },
        { selector: '.app-venues-catalog__filters', title: 'Фильтры площадок' },
        { selector: '.app-team-filters', title: 'Фильтры команд' },
        { selector: '[data-participant-catalog-filters]', title: 'Фильтры участников' },
        { selector: '.app-favorites__filter-form', title: 'Фильтры избранного', ajax: true },
        { selector: '.app-play-interests__filters', title: 'Фильтры подписок', ajax: true },
        { selector: '.app-my-games__filters', title: 'Мои игры', navigation: true },
    ];
    const definition = origins.map(spec => ({ ...spec, source: document.querySelector(spec.selector) }))
        .find(spec => spec.source);
    const original = definition?.source ?? null;
    let placeholder = null;
    let mounted = false;
    let opened = null;
    let activeForm = globalForm;
    let activeSearch = globalSearch;
    let activeCount = 0;
    let expanded = false;

    const selectedScope = () => {
        const url = new URL(window.location.href);
        const keys = new Set(['q', 'search', 'type', 'status', 'period', 'date_from', 'date_to',
            'outcome', 'venue_id', 'has_mini_games', 'query', 'season', 'training_mode',
            'game_format', 'pricing_type', 'team_id', 'accepts_requests', 'recruiting',
            'tag', 'product_type', 'stock', 'color', 'size', 'min_price', 'max_price',
            'sport_type', 'member_count', 'hiring', 'access', 'operational_status',
            'has_upcoming_events', 'latitude', 'longitude', 'radius_km', 'scope', 'role']);
        const defaults = { period: ['upcoming', 'all'], status: ['not_cancelled'] };
        let count = 0;
        for (const [key, val] of url.searchParams) {
            if (!keys.has(key) || !val || defaults[key]?.includes(val)) continue;
            if (key === 'role' && /\/(players|coaches)$/.test(url.pathname)) continue;
            if (['longitude', 'radius_km'].includes(key) && url.searchParams.has('latitude')) continue;
            count++;
        }
        return count;
    };

    const updateCount = () => {
        if (!definition) {
            filterCount.textContent = 'Поиск по всем разделам MSKBA';
            activeCount = 0;
            return;
        }
        activeCount = selectedScope();
        const result = document.querySelector(
            '[data-default-category] .app-category__count, ' +
            '[data-default-category] [data-venue-visible-count], ' +
            '.app-shop__toolbar-count, [data-favorites-count], [data-subscriptions-count]'
        );
        filterCount.textContent = result?.closest('[role="status"]')?.textContent?.trim()
            || result?.textContent?.trim() || 'Поиск и фильтры раздела';
        if (filterBadge) {
            filterBadge.hidden = activeCount === 0;
            filterBadge.textContent = String(activeCount);
        }
    };

    const setExpanded = (value) => {
        expanded = Boolean(value);
        filterPanel?.classList.toggle('is-expanded', expanded);
        filterExpand?.setAttribute('aria-expanded', String(expanded));
        filterExpand?.setAttribute('aria-label', expanded ? 'Свернуть фильтры' : 'Развернуть фильтры');
        if (filterScroll) filterScroll.scrollTop = 0;
    };

    const mountFilters = () => {
        if (mounted || !filterHost || !globalForm) return;
        mounted = true;
        filterHost.replaceChildren();
        if (definition && original) {
            filterLabel.textContent = 'Фильтры';
            filterTitle.textContent = definition.title;
            globalForm.hidden = true;
            if (definition.ajax) {
                // Keep the real form inside its original component: its JS resolves
                // fields from the original root and binds listeners to those nodes.
                original.classList.add('app-mobile-primary__origin-hidden');
                const copy = original.cloneNode(true);
                copy.removeAttribute('data-favorites-filters');
                copy.removeAttribute('data-subscriptions-filters');
                copy.querySelectorAll('[id]').forEach(el => {
                    const previous = el.id;
                    el.id = 'dock-' + previous;
                    copy.querySelectorAll('label[for="' + previous + '"]')
                        .forEach(label => { label.htmlFor = el.id; });
                });
                copy.querySelectorAll('[data-favorites-reset],[data-subscriptions-reset]')
                    .forEach(el => { el.hidden = true; });
                filterHost.append(copy);
                activeForm = copy;
            } else {
                placeholder = document.createComment('mobile filters original position');
                original.parentNode?.insertBefore(placeholder, original);
                filterHost.append(original);
                original.closest('details')?.removeAttribute('open');
                const shell = placeholder.parentElement?.closest('details');
                shell?.classList.add('app-mobile-primary__origin-hidden');
                activeForm = original.matches('form') ? original : original.querySelector('form');
            }
            activeSearch = activeForm?.querySelector('input[type="search"]') ?? null;
        } else {
            filterLabel.textContent = 'Поиск';
            filterTitle.textContent = 'Поиск по MSKBA';
            globalForm.hidden = false;
            activeForm = globalForm;
            activeSearch = globalSearch;
        }
        filterPreview.hidden = !activeSearch;
        if (activeSearch) {
            quickSearch.value = activeSearch.value;
            quickSearch.placeholder = activeSearch.placeholder || 'Что хотите найти?';
            activeSearch.addEventListener('input', () => {
                if (document.activeElement !== quickSearch) quickSearch.value = activeSearch.value;
            });
        }
        updateCount();
        setExpanded(Boolean(definition && activeCount > 0));
    };

    const unmountFilters = () => {
        if (!mounted) return;
        if (original) {
            if (definition.ajax) {
                original.classList.remove('app-mobile-primary__origin-hidden');
                filterHost.replaceChildren();
            } else if (placeholder?.parentNode) {
                placeholder.replaceWith(original);
                original.closest('details')?.classList.remove('app-mobile-primary__origin-hidden');
            }
        }
        mounted = false;
        activeForm = globalForm;
        activeSearch = globalSearch;
    };

    const close = (restoreFocus = false) => {
        const current = opened ? contexts[opened] : null;
        if (!current) return;
        current.panel.hidden = true;
        current.toggle?.setAttribute('aria-expanded', 'false');
        current.toggle?.setAttribute('aria-label', opened === 'menu' ? 'Открыть меню'
            : opened === 'play' ? 'Открыть раздел Играть' : 'Открыть поиск');
        backdrop.hidden = true;
        document.body.classList.remove('app-mobile-primary-open');
        opened = null;
        if (restoreFocus && mobile.matches) current.toggle?.focus({ preventScroll: true });
    };

    const open = (name) => {
        if (!mobile.matches) return;
        if (opened === name) { close(true); return; }
        close(false);
        if (name === 'filter') {
            mountFilters();
            updateCount();
            setExpanded(Boolean(definition && activeCount > 0));
        }
        const item = contexts[name];
        if (!item?.panel || !item?.toggle) return;
        opened = name;
        item.panel.hidden = false;
        backdrop.hidden = false;
        document.body.classList.add('app-mobile-primary-open');
        item.toggle.setAttribute('aria-expanded', 'true');
        item.toggle.setAttribute('aria-label', name === 'filter' ? 'Закрыть фильтры' : 'Закрыть меню');
        if (name === 'filter' && activeSearch) quickSearch.focus({ preventScroll: true });
        else item.panel.querySelector('[data-app-mobile-primary-close]')?.focus({ preventScroll: true });
    };

    for (const [name, item] of Object.entries(contexts)) {
        item.toggle?.addEventListener('click', () => open(name));
        item.panel?.querySelectorAll('[data-app-mobile-primary-close]')
            .forEach(button => button.addEventListener('click', () => close(true)));
        item.panel?.querySelectorAll('a[href]')
            .forEach(link => link.addEventListener('click', () => close()));
    }

    let backdropPointer = null;
    backdrop?.addEventListener('pointerdown', event => {
        backdropPointer = event.target === backdrop ? event.pointerId : null;
    });
    backdrop?.addEventListener('pointerup', event => {
        if (event.target === backdrop && event.pointerId === backdropPointer) close(true);
        backdropPointer = null;
    });
    backdrop?.addEventListener('pointercancel', () => { backdropPointer = null; });

    document.addEventListener('keydown', event => {
        if (!opened) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            close(true);
            return;
        }
        if (event.key !== 'Tab') return;
        const panel = contexts[opened].panel;
        const focusables = [...panel.querySelectorAll(
            'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),summary'
        )].filter(el => !el.closest('[hidden]') && el.getClientRects().length > 0);
        if (!focusables.length) return;
        if (event.shiftKey && document.activeElement === focusables[0]) {
            event.preventDefault();
            focusables.at(-1).focus();
        } else if (!event.shiftKey && document.activeElement === focusables.at(-1)) {
            event.preventDefault();
            focusables[0].focus();
        }
    });

    const syncQuickSearch = () => {
        if (activeSearch && quickSearch && document.activeElement === quickSearch) {
            activeSearch.value = quickSearch.value;
        }
    };
    quickSearch?.addEventListener('input', syncQuickSearch);
    quickSearch?.addEventListener('keydown', event => {
        if (event.key === 'Enter') {
            event.preventDefault();
            apply?.click();
        }
    });
    filterExpand?.addEventListener('click', () => setExpanded(!expanded));

    apply?.addEventListener('click', () => {
        syncQuickSearch();
        if (definition?.ajax && original && activeForm) {
            const cloned = [...activeForm.querySelectorAll('select,input[name]')];
            const changed = [];
            for (const element of cloned) {
                if (!element.name) continue;
                const originalElement = original.querySelector('[name="' + CSS.escape(element.name) + '"]');
                if (!originalElement || originalElement.value === element.value) continue;
                originalElement.value = element.value;
                changed.push(originalElement);
            }
            if (changed.length) {
                // Both original AJAX handlers listen to change, either on form or controls.
                const dispatcher = definition.selector.includes('favorites')
                    ? changed[0] : original.querySelector('select');
                dispatcher?.dispatchEvent(new Event('change', { bubbles: true }));
            }
            close(true);
        } else if (definition?.navigation) {
            close(true);
        } else if (activeForm?.requestSubmit) {
            activeForm.requestSubmit();
        }
    });

    reset?.addEventListener('click', () => {
        if (definition?.ajax && original) {
            const resetButton = original.querySelector('[data-favorites-reset],[data-subscriptions-reset]');
            if (resetButton) resetButton.click();
            else original.reset();
            activeForm?.reset();
            updateCount();
            close(true);
            return;
        }
        if (definition?.navigation) {
            const allLink = activeForm?.querySelector('a[href]');
            if (allLink) window.location.assign(allLink.href);
            return;
        }
        if (!definition) {
            globalForm?.reset();
            if (globalSearch) globalSearch.value = '';
            if (quickSearch) quickSearch.value = '';
            setExpanded(false);
            return;
        }
        const action = activeForm?.action;
        if (!action) return;
        const url = new URL(action, window.location.href);
        const current = new URL(window.location.href);
        for (const key of ['view', 'sort']) {
            if (current.searchParams.has(key)) url.searchParams.set(key, current.searchParams.get(key));
        }
        if (current.searchParams.get('type') === 'system_update' && current.pathname.startsWith('/news')) {
            url.searchParams.set('type', 'system_update');
        }
        window.location.assign(url);
    });

    mobile.addEventListener('change', () => {
        if (!mobile.matches) {
            close(false);
            unmountFilters();
        } else {
            mountFilters();
        }
    });
    if (mobile.matches) mountFilters();
}
