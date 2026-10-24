// Enrich the actual custom MSKBA venue card in the Filament Page Builder
// Yandex map. The venue scene owns its placemarks and popup; this adapter
// neither wraps the Yandex constructor nor adds competing map markers.

export function groupTodayEvents(events) {
    const groups = new Map();
    for (const event of Array.isArray(events) ? events : []) {
        const venueId = Number(event?.venue_id);
        if (!Number.isSafeInteger(venueId) || venueId < 1) continue;
        const key = String(venueId);
        if (!groups.has(key)) groups.set(key, { venue_id: venueId, events: [] });
        groups.get(key).events.push(event);
    }
    return Array.from(groups.values());
}

export function safeEventLink(url) {
    try {
        const origin = typeof window !== 'undefined' ? window.location.origin : 'https://mskba.ru';
        const parsed = new URL(String(url), origin);
        return parsed.origin === origin && /^\/events\/[^/]+\/?$/.test(parsed.pathname)
            ? parsed.pathname + parsed.search
            : null;
    } catch {
        return null;
    }
}

export function venueIdFromLink(url) {
    try {
        const origin = typeof window !== 'undefined' ? window.location.origin : 'https://mskba.ru';
        const parsed = new URL(String(url), origin);
        if (parsed.origin !== origin) return null;
        const match = /^\/venues\/(\d+)(?:[-/]|$)/.exec(parsed.pathname);
        return match ? Number(match[1]) : null;
    } catch {
        return null;
    }
}

export function eventCardData(events, venueId) {
    if (!Number.isSafeInteger(venueId)) return [];
    return (Array.isArray(events) ? events : [])
        .filter(item => Number(item?.venue_id) === venueId && safeEventLink(item.url))
        .sort((left, right) => String(left.starts_at).localeCompare(String(right.starts_at)));
}

function eventSection(events) {
    const section = document.createElement('section');
    section.className = 'mskba-venues-map__today-events';

    const heading = document.createElement('div');
    heading.className = 'mskba-venues-map__today-heading';
    heading.textContent = events.length > 1 ? 'Сегодня здесь — ' + events.length + ' мероприятия' : 'Сегодня здесь';
    section.append(heading);

    for (const event of events) {
        const item = document.createElement('div');
        item.className = 'mskba-venues-map__today-item';
        const details = document.createElement('div');
        details.className = 'mskba-venues-map__today-details';
        const name = document.createElement('strong');
        name.textContent = String(event.title || 'Мероприятие');
        const when = document.createElement('small');
        when.textContent = [event.type_label, event.starts_at && event.ends_at
            ? event.starts_at + '–' + event.ends_at
            : event.starts_at].filter(Boolean).join(' · ');
        details.append(name, when);
        const link = document.createElement('a');
        link.href = safeEventLink(event.url);
        link.className = 'mskba-venues-map__today-link';
        link.textContent = 'Подробнее →';
        item.append(details, link);
        section.append(item);
    }
    return section;
}

function initialize() {
    if (!['/', ''].includes(window.location.pathname)) return;
    const scene = document.querySelector('.mskba-venues-map');
    const mount = scene?.querySelector('#mskba-venues-map-card');
    if (!mount || mount.dataset.mskbaTodayEventCards === '1') return;
    mount.dataset.mskbaTodayEventCards = '1';

    let events = [];
    let lastUpdated = 0;
    let inFlight = null;

    function decorate() {
        const card = mount.querySelector('.mskba-venues-map__venue-card');
        if (!card) return;
        const existing = card.querySelector('.mskba-venues-map__today-events');
        existing?.remove();

        const venueLink = card.querySelector('a.mskba-venues-map__card-link');
        const venueId = venueIdFromLink(venueLink?.getAttribute('href') || '');
        const today = eventCardData(events, venueId);
        if (!today.length) return;

        const actions = card.querySelector('.mskba-venues-map__card-actions');
        const body = card.querySelector('.mskba-venues-map__card-body');
        if (!body) return;
        const section = eventSection(today);
        if (actions) body.insertBefore(section, actions);
        else body.append(section);
    }

    async function refresh(force = false) {
        if (inFlight) return inFlight;
        if (!force && Date.now() - lastUpdated < 30_000) return;
        inFlight = (async () => {
            try {
                const response = await fetch('/home/today-event-map', {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                    cache: 'no-store',
                });
                if (!response.ok) throw new Error('HTTP ' + response.status);
                const data = await response.json();
                events = Array.isArray(data.events) ? data.events : [];
                lastUpdated = Date.now();
                decorate();
            } catch (error) {
                console.warn('[MSKBA Today Events] Could not load events for venue cards', error);
            } finally {
                inFlight = null;
            }
        })();
        return inFlight;
    }

    // The Page Builder scene populates #mskba-venues-map-card via
    // replaceChildren() on marker click. Observe that real popup container,
    // not Yandex balloons (which this map never opens).
    const observer = new MutationObserver(() => { decorate(); void refresh(); });
    observer.observe(mount, { childList: true });
    scene.addEventListener('click', () => { void refresh(); });
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) void refresh(true);
    });
    void refresh();
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, { once: true });
    } else {
        initialize();
    }
}
