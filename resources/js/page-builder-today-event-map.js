// A non-invasive adapter for the Page Builder's existing Yandex 2.x venue map.
// Venue markers remain owned by the published Page Builder code.
const homepage = typeof document !== 'undefined'
    && document.querySelector('.mskba-page-builder')
    && (window.location.pathname === '/' || window.location.pathname === '');

export function groupTodayEvents(events) {
    const groups = new Map();

    for (const event of Array.isArray(events) ? events : []) {
        if (event.latitude === null || event.latitude === undefined || event.latitude === ''
            || event.longitude === null || event.longitude === undefined || event.longitude === '') continue;
        const latitude = Number(event.latitude);
        const longitude = Number(event.longitude);
        if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) continue;
        const key = String(event.venue_id);
        if (!groups.has(key)) {
            groups.set(key, { latitude, longitude, events: [] });
        }
        groups.get(key).events.push(event);
    }

    return Array.from(groups.values());
}

export function safeEventLink(url) {
    try {
        const parsed = new URL(String(url), window.location.origin);
        return parsed.origin === window.location.origin && /^\/events\/[^/]+/.test(parsed.pathname)
            ? parsed.pathname + parsed.search
            : null;
    } catch {
        return null;
    }
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (char) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[char]));
}

function createBalloon(group) {
    const events = group.events;
    return events.map((event) => {
        const url = safeEventLink(event.url);
        return [
            '<article style="min-width:230px;max-width:285px;padding:5px 0">',
            '<strong>' + escapeHtml(event.title) + '</strong>',
            '<div>' + escapeHtml(event.type_label) + ' · '
                + escapeHtml(event.starts_at) + '–' + escapeHtml(event.ends_at) + '</div>',
            '<small>' + escapeHtml(event.venue_name) + '</small>',
            url ? '<div><a href="' + escapeHtml(url)
                + '" style="font-weight:700">Подробнее →</a></div>' : '',
            '</article>',
        ].join('');
    }).join('<hr style="border:0;border-top:1px solid #eee;margin:5px 0">');
}

function mapElement(root) {
    if (root instanceof HTMLElement) return root;
    if (typeof root === 'string') return document.getElementById(root);
    return null;
}

function isHomepageVenueMap(container) {
    if (!container?.closest('.mskba-page-builder')) return false;
    const section = container.closest('section');
    if (!section) return false;

    // The actual Page Builder scene lives in versioned Filament HTML/JS, not
    // the legacy Blade page. Scope to its heading so unrelated maps are untouched.
    return /НАЙДИ СВОЮ|КАРТА ПЛОЩАДОК/i.test(section.textContent || '');
}

function attach(map, container) {
    if (!map || map.__mskbaTodayEventsAttached || !isHomepageVenueMap(container)) return;
    map.__mskbaTodayEventsAttached = true;
    let markers = [];

    async function refresh() {
        try {
            const response = await fetch('/home/today-event-map', {
                credentials: 'same-origin', headers: { Accept: 'application/json' },
            });
            if (!response.ok) return;
            const payload = await response.json();
            if (!window.ymaps?.Placemark || !map.geoObjects) return;

            for (const marker of markers) map.geoObjects.remove(marker);
            markers = [];

            for (const group of groupTodayEvents(payload.events)) {
                const marker = new window.ymaps.Placemark(
                    [group.latitude, group.longitude],
                    {
                        hintContent: group.events.length > 1
                            ? 'Мероприятия сегодня: ' + group.events.length
                            : group.events[0].title,
                        balloonContentHeader: 'Сегодня на площадке',
                        balloonContentBody: createBalloon(group),
                    },
                    {
                        preset: 'islands#redCircleDotIcon',
                        iconOffset: [10, -10],
                        zIndex: 10000,
                    },
                );
                map.geoObjects.add(marker);
                markers.push(marker);
            }
        } catch {
            // Map remains usable even when the optional event feed fails.
        }
    }

    void refresh();
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) void refresh();
    });

    // Stable external integration point for future Page Builder map versions.
    window.MSKBATodayEventMap = { attach, refresh };
}

if (homepage) {
    // Page Builder Yandex maps are usually lazy-initialized when the section
    // enters the viewport. Wrap the constructor before that point; do not
    // replace or clear existing venue placemarks.
    const ticker = setInterval(() => {
        const ymaps = window.ymaps;
        const NativeMap = ymaps?.Map;
        // The Page Builder lazily loads the map on scroll; it may not even
        // request Yandex until the user reaches the scene much later.
        if (typeof NativeMap !== 'function') return;
        clearInterval(ticker);
        if (NativeMap.__mskbaTodayEventsWrapped) return;

        function MapWithTodayEvents(...args) {
            const map = new NativeMap(...args);
            const element = mapElement(args[0]);
            if (element) queueMicrotask(() => attach(map, element));
            return map;
        }
        MapWithTodayEvents.prototype = NativeMap.prototype;
        Object.setPrototypeOf(MapWithTodayEvents, NativeMap);
        MapWithTodayEvents.__mskbaTodayEventsWrapped = true;
        try {
            ymaps.Map = MapWithTodayEvents;
        } catch {
            // Some Map API builds expose a non-writable constructor.
        }
    }, 80);
}
