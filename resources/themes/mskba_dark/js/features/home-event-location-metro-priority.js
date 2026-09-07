const METRO_INPUT_SELECTOR = '[data-home-location-metro-input]';
const METRO_LIST_SELECTOR = '[data-home-location-metro-list]';

function normalize(value) {
    return String(value || '')
        .replace(/\s+/g, ' ')
        .trim()
        .toLocaleLowerCase('ru');
}

function sortVisibleMetroResults(input) {
    const wrap = input?.closest('.home-event-location__metro-predictive');
    const list = wrap?.querySelector(METRO_LIST_SELECTOR);
    const query = normalize(input?.value);

    if (!list || query.length < 2) {
        return;
    }

    const current = [...list.querySelectorAll('.home-event-location__metro-result')];
    if (current.length < 2) {
        return;
    }

    const sorted = current
        .map((button, index) => ({
            button,
            index,
            name: normalize(button.querySelector('strong')?.textContent),
        }))
        .sort((left, right) => {
            const leftStarts = left.name.startsWith(query);
            const rightStarts = right.name.startsWith(query);

            if (leftStarts !== rightStarts) {
                return leftStarts ? -1 : 1;
            }

            const alphabetical = left.name.localeCompare(right.name, 'ru', {
                sensitivity: 'base',
                numeric: true,
            });

            return alphabetical || left.index - right.index;
        })
        .map(({ button }) => button);

    if (sorted.every((button, index) => button === current[index])) {
        return;
    }

    const fragment = document.createDocumentFragment();
    sorted.forEach((button) => fragment.append(button));
    list.append(fragment);
}

const observedLists = new WeakSet();

function observeMetroList(list) {
    if (!list || observedLists.has(list)) {
        return;
    }

    observedLists.add(list);
    const observer = new MutationObserver(() => {
        const input = list.closest('.home-event-location__metro-predictive')?.querySelector(METRO_INPUT_SELECTOR);
        if (!input) {
            return;
        }

        queueMicrotask(() => sortVisibleMetroResults(input));
    });

    observer.observe(list, { childList: true });
}

function discoverMetroLists(root = document) {
    if (root instanceof Element && root.matches(METRO_LIST_SELECTOR)) {
        observeMetroList(root);
    }

    root.querySelectorAll?.(METRO_LIST_SELECTOR).forEach(observeMetroList);
}

discoverMetroLists();

const documentObserver = new MutationObserver((mutations) => {
    mutations.forEach((mutation) => {
        mutation.addedNodes.forEach((node) => {
            if (node instanceof Element) {
                discoverMetroLists(node);
            }
        });
    });
});

documentObserver.observe(document.documentElement, {
    childList: true,
    subtree: true,
});

document.addEventListener('input', (event) => {
    const input = event.target.closest?.(METRO_INPUT_SELECTOR);
    if (!input) {
        return;
    }

    window.setTimeout(() => sortVisibleMetroResults(input), 190);
});

document.addEventListener('focusin', (event) => {
    const input = event.target.closest?.(METRO_INPUT_SELECTOR);
    if (!input) {
        return;
    }

    window.setTimeout(() => sortVisibleMetroResults(input), 0);
});
