const configuredMapContainers = new WeakMap();

export function loadYandexMaps(apiKey) {
    if (window.ymaps) {
        return ensureCooperativeMapDefaults();
    }

    if (window.mskbaYandexMapsLoading) {
        return window.mskbaYandexMapsLoading;
    }

    window.mskbaYandexMapsLoading = new Promise((resolve, reject) => {
        const script = document.createElement('script');
        const params = new URLSearchParams({
            apikey: apiKey,
            lang: 'ru_RU',
        });

        script.src = `https://api-maps.yandex.ru/2.1/?${params.toString()}`;
        script.async = true;
        script.onload = () => {
            ensureCooperativeMapDefaults().then(resolve, reject);
        };
        script.onerror = reject;
        document.head.appendChild(script);
    });

    return window.mskbaYandexMapsLoading;
}

function ensureCooperativeMapDefaults() {
    if (!window.ymaps?.ready) {
        return Promise.resolve();
    }

    return new Promise((resolve) => {
        window.ymaps.ready(() => {
            installCooperativeMapConstructor();
            resolve();
        });
    });
}

function installCooperativeMapConstructor() {
    const NativeMap = window.ymaps?.Map;

    if (!NativeMap || NativeMap.__mskbaCooperativeWrapper) {
        return;
    }

    function CooperativeMap(container, state, options) {
        const map = new NativeMap(container, state, options);
        configureCooperativeInteractions(map, container);

        return map;
    }

    CooperativeMap.prototype = NativeMap.prototype;
    Object.setPrototypeOf(CooperativeMap, NativeMap);
    Object.defineProperty(CooperativeMap, '__mskbaCooperativeWrapper', { value: true });
    Object.defineProperty(CooperativeMap, '__mskbaNativeMap', { value: NativeMap });

    window.ymaps.Map = CooperativeMap;
}

function configureCooperativeInteractions(map, container) {
    const element = resolveMapContainer(container);

    if (!element || !map?.behaviors) {
        return;
    }

    // Page scrolling must win over an accidental map zoom. Deliberate desktop zoom
    // remains available with Ctrl/Cmd + wheel and through the map's own controls.
    map.behaviors.disable('scrollZoom');

    if (hasTouchPrimaryPointer()) {
        // One-finger gestures are reserved for scrolling the page. Yandex multiTouch
        // stays enabled, so a deliberate two-finger gesture can still move/zoom the map.
        map.behaviors.disable('drag');
        map.behaviors.enable('multiTouch');
    }

    configuredMapContainers.get(element)?.cleanup();

    let viewportFrame = null;
    const refreshViewport = () => {
        if (!element.isConnected || element.offsetWidth <= 0 || element.offsetHeight <= 0) {
            return;
        }

        if (viewportFrame !== null) {
            window.cancelAnimationFrame(viewportFrame);
        }

        viewportFrame = window.requestAnimationFrame(() => {
            viewportFrame = null;

            if (!element.isConnected || element.offsetWidth <= 0 || element.offsetHeight <= 0) {
                return;
            }

            map.container?.fitToViewport?.();
        });
    };

    // Catalog maps are intentionally hidden when another view is active. Yandex Maps
    // keeps the old canvas size while an ancestor is display:none, which can leave a
    // blank/stale map after switching back until the user touches it. Refresh every time
    // the real container becomes measurable again, not only during initial construction.
    const resizeObserver = typeof window.ResizeObserver === 'function'
        ? new window.ResizeObserver((entries) => {
            if (entries.some((entry) => entry.contentRect.width > 0 && entry.contentRect.height > 0)) {
                refreshViewport();
            }
        })
        : null;

    resizeObserver?.observe(element);
    refreshViewport();

    const onWheel = (event) => {
        if (!event.ctrlKey && !event.metaKey) {
            return;
        }

        event.preventDefault();
        const direction = event.deltaY < 0 ? 1 : -1;
        const currentZoom = Number(map.getZoom());

        if (Number.isFinite(currentZoom)) {
            map.setZoom(currentZoom + direction, {
                checkZoomRange: true,
                duration: 120,
            });
        }
    };

    element.addEventListener('wheel', onWheel, { passive: false });
    element.dataset.mapCooperativeInteraction = hasTouchPrimaryPointer() ? 'two-finger' : 'modifier-wheel';

    const cleanup = () => {
        element.removeEventListener('wheel', onWheel);
        resizeObserver?.disconnect();
        if (viewportFrame !== null) {
            window.cancelAnimationFrame(viewportFrame);
        }
        configuredMapContainers.delete(element);
    };

    configuredMapContainers.set(element, { cleanup });
    map.events?.add?.('destroy', cleanup);
}

function resolveMapContainer(container) {
    if (container instanceof Element) {
        return container;
    }

    if (typeof container === 'string') {
        return document.getElementById(container);
    }

    return null;
}

function hasTouchPrimaryPointer() {
    if (typeof window.matchMedia === 'function') {
        return window.matchMedia('(pointer: coarse)').matches;
    }

    return navigator.maxTouchPoints > 0;
}
