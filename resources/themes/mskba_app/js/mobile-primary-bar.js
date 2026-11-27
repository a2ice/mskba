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
    const filterResultValue = dock.querySelector('[data-context-filter-result-value]');
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
    const toolbarSearch = definition && !definition.ajax && !definition.navigation
        ? document.querySelector('.app-category-toolbar--compact .app-category-toolbar__search')
        : null;
    const toolbarDesktopForm = toolbarSearch?.getAttribute('form');
    let replacedSearch = null;
    let placeholder = null;
    let mounted = false;
    let opened = null;
    let activeForm = globalForm;
    let activeSearch = globalSearch;
    let activeCount = 0;
    let expanded = false;

    const selectedScope = () => {
        const landingCount = original?.getAttribute('data-venue-landing-filter-count');
        if (landingCount !== null && landingCount !== undefined) return Number(landingCount);
        const url = new URL(window.location.href);
        const keys = new Set(['q', 'search', 'type', 'status', 'period', 'date_from', 'date_to',
            'outcome', 'venue_id', 'has_mini_games', 'query', 'season', 'training_mode',
            'game_format', 'pricing_type', 'team_id', 'accepts_requests', 'recruiting',
            'tag', 'product_type', 'stock', 'color', 'size', 'min_price', 'max_price',
            'sport_type', 'member_count', 'hiring', 'access', 'operational_status',
            'has_upcoming_events', 'my_interests', 'interest_id', 'rental_configured', 'latitude', 'longitude', 'radius_km', 'scope', 'role']);
        const defaults = { period: ['upcoming', 'all'], status: ['not_cancelled'] };
        let count = 0;
        let venueTypeCounted = false;
        for (const [rawKey, val] of url.searchParams) {
            const key = /^type\[(?:\d*)\]$/.test(rawKey) && /^\/venues(?:\/|$)/.test(url.pathname) ? 'type' : rawKey;
            if (key === 'type' && /^\/venues(?:\/|$)/.test(url.pathname) && val) {
                if (venueTypeCounted) continue;
                venueTypeCounted = true;
            }
            if (!keys.has(key) || !val || defaults[key]?.includes(val)) continue;
            if (key === 'rental_configured' && val !== '1') continue;
            if (key === 'role' && /\/(players|coaches)$/.test(url.pathname)) continue;
            if (['longitude', 'radius_km'].includes(key) && url.searchParams.has('latitude')) continue;
            count++;
        }
        if (definition?.ajax && original) {
            for (const input of original.querySelectorAll('select')) {
                const defaults = { type: 'all', state: 'active', status: 'active' };
                const baseline = defaults[input.name] ?? input.querySelector('option')?.value ?? '';
                if (input.value !== baseline && !url.searchParams.has(input.name)) count++;
            }
        }
        return count;
    };

    // Header favourites badge is not the current catalog total. Always prefer
    // the catalog's server-supplied total; only use AJAX counters scoped to
    // their respective account pages (never the global header badge).
    const countSource = () => {
        const catalogCount = document.querySelector('[data-context-result-total]');
        if (catalogCount) return catalogCount;
        if (definition?.selector === '.app-favorites__filter-form') {
            return document.querySelector('[data-favorites-account] [data-favorites-count]');
        }
        if (definition?.selector === '.app-play-interests__filters') {
            return document.querySelector('[data-play-interests] [data-subscriptions-count]');
        }
        return null;
    };

    const updateCount = () => {
        activeCount = definition ? selectedScope() : 0;
        if (filterBadge) {
            filterBadge.hidden = activeCount === 0;
            filterBadge.textContent = String(activeCount);
        }

        // The result count is NOT the number of active filters, nor the number
        // of cards loaded so far by infinite scroll. Use the server paginator
        // total; for AJAX account lists read the live resolved result count.
        const source = countSource();
        const explicit = source?.getAttribute('data-context-result-total');
        const text = source?.textContent?.trim() || '';
        const numeric = source?.hasAttribute('data-context-result-total')
            ? (/^\d+$/.test(explicit) ? explicit : null)
            : (text.match(/(?:^|\D)(\d[\d\s]*)\s*$/u)?.[1]?.replace(/\s/g, '') ?? null);
        if (filterResultValue) filterResultValue.textContent = numeric ?? '—';
    };

    const setExpanded = (value) => {
        expanded = Boolean(value);
        filterPanel?.classList.toggle('is-expanded', expanded);
        filterExpand?.setAttribute('aria-expanded', String(expanded));
        const hint = expanded ? 'Свернуть фильтры' : 'Развернуть фильтры';
        filterExpand?.setAttribute('aria-label', hint);
        filterExpand?.setAttribute('title', hint);
        if (filterScroll) filterScroll.scrollTop = 0;
    };

    const mountFilters = () => {
        if (mounted || !filterHost || !globalForm) return;
        mounted = true;
        filterHost.replaceChildren();
        if (definition && original) {
            filterLabel.textContent = 'Поиск';
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
            // Use one visible catalog search, associated with the real mobile GET form.
            if (toolbarSearch && activeForm?.id) {
                replacedSearch = activeForm.querySelector('input[type="search"]');
                if (replacedSearch && replacedSearch !== toolbarSearch) {
                    replacedSearch.disabled = true;
                }
                toolbarSearch.setAttribute('form', activeForm.id);
                activeSearch = toolbarSearch;
            } else {
                activeSearch = activeForm?.querySelector('input[type="search"]')
                    ?? activeForm?.elements?.namedItem('search') ?? null;
            }
        } else {
            filterLabel.textContent = 'Поиск';
            filterTitle.textContent = 'Поиск по MSKBA';
            globalForm.hidden = false;
            activeForm = globalForm;
            activeSearch = globalSearch;
        }
        const usesToolbarSearch = Boolean(toolbarSearch && activeSearch === toolbarSearch);
        filterPanel?.classList.toggle('is-toolbar-search', usesToolbarSearch);
        dock.classList.toggle('has-catalog-toolbar-search', usesToolbarSearch);
        if (usesToolbarSearch) {
            filterLabel.textContent = 'Фильтры';
            contexts.filter.toggle?.setAttribute('aria-label', 'Открыть фильтры');
        }
        filterPreview.hidden = usesToolbarSearch || !activeSearch;
        if (activeSearch && !usesToolbarSearch) {
            quickSearch.value = activeSearch.value;
            quickSearch.placeholder = activeSearch.placeholder || 'Что хотите найти?';
            activeSearch.addEventListener('input', () => {
                if (document.activeElement !== quickSearch) quickSearch.value = activeSearch.value;
            });
        }
        updateCount();
        setExpanded(Boolean(toolbarSearch && activeSearch === toolbarSearch) || (definition ? activeCount > 0 : Boolean(globalSearch?.value)));
    };

    const unmountFilters = () => {
        if (!mounted) return;
        if (toolbarSearch && toolbarDesktopForm) {
            toolbarSearch.setAttribute('form', toolbarDesktopForm);
            if (replacedSearch && replacedSearch !== toolbarSearch) {
                replacedSearch.value = toolbarSearch.value;
                replacedSearch.disabled = false;
            }
            replacedSearch = null;
            filterPanel?.classList.remove('is-toolbar-search');
            dock.classList.remove('has-catalog-toolbar-search');
        }
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
            : opened === 'play' ? 'Открыть раздел Играть' : toolbarSearch ? 'Открыть фильтры' : 'Открыть поиск');
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
            setExpanded(Boolean(toolbarSearch && activeSearch === toolbarSearch) || (definition ? activeCount > 0 : Boolean(globalSearch?.value)));
        }
        const item = contexts[name];
        if (!item?.panel || !item?.toggle) return;
        opened = name;
        item.panel.hidden = false;
        backdrop.hidden = false;
        document.body.classList.add('app-mobile-primary-open');
        item.toggle.setAttribute('aria-expanded', 'true');
        item.toggle.setAttribute('aria-label', name === 'filter' ? 'Закрыть фильтры' : 'Закрыть меню');
        // Opening filters should not summon the iOS keyboard unexpectedly.
        item.panel.querySelector('[data-app-mobile-primary-close]')?.focus({ preventScroll: true });
    };

    for (const [name, item] of Object.entries(contexts)) {
        item.toggle?.addEventListener('click', () => open(name));
        item.panel?.querySelectorAll('[data-app-mobile-primary-close]')
            .forEach(button => button.addEventListener('click', () => close(true)));
        item.panel?.querySelectorAll('a[href]')
            .forEach(link => link.addEventListener('click', () => close()));
    }

    // The standard subscription wizard is a native top-layer dialog. Release
    // the dock focus trap before Vue opens it; never create a second wizard.
    filterPanel?.addEventListener('click', event => {
        if (event.target.closest?.('[data-subscription-create]')) close(false);
    });

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

    toolbarSearch?.addEventListener('keydown', event => {
        if (event.key !== 'Enter' || event.isComposing || !mobile.matches || !mounted) return;
        event.preventDefault();
        toolbarSearch.form?.requestSubmit();
    });

    const syncQuickSearch = () => {
        if (activeSearch && activeSearch !== toolbarSearch && quickSearch && document.activeElement === quickSearch) {
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

    // VisualViewport reports the area left above Safari's software keyboard.
    // Keep the dock and its fixed action row visible without scrolling the page.
    const placeAboveKeyboard = () => {
        const viewport = window.visualViewport;
        if (!viewport || !mobile.matches) {
            dock.style.removeProperty('--app-mobile-keyboard-offset');
            dock.style.removeProperty('--app-mobile-visual-height');
            return;
        }
        const keyboard = Math.max(0, window.innerHeight - viewport.height - viewport.offsetTop);
        dock.style.setProperty('--app-mobile-keyboard-offset', Math.round(keyboard) + 'px');
        dock.style.setProperty('--app-mobile-visual-height', Math.round(viewport.height) + 'px');
    };
    window.visualViewport?.addEventListener('resize', placeAboveKeyboard);
    window.visualViewport?.addEventListener('scroll', placeAboveKeyboard);
    placeAboveKeyboard();

    // AJAX lists update their visible result counter without page navigation.
    // Observe only the counter node (not the dock) to avoid feedback loops.
    const liveCount = definition?.ajax ? countSource() : null;
    if (liveCount) {
        new MutationObserver(updateCount).observe(liveCount, {
            childList: true, characterData: true, subtree: true,
        });
    }

    const syncMobileContext = () => {
        document.documentElement.classList.toggle('has-app-mobile-context', mobile.matches);
    };
    syncMobileContext();

    mobile.addEventListener('change', () => {
        if (!mobile.matches) {
            close(false);
            unmountFilters();
        } else {
            mountFilters();
        }
        placeAboveKeyboard();
        syncMobileContext();
    });
    if (mobile.matches) mountFilters();
}
