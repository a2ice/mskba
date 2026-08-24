import { subscribePublic } from '../../../../js/realtime.js';

const venuePage = document.querySelector('.venue-show');

if (venuePage) {
    const match = window.location.pathname.match(/^\/venues\/([^/]+)\/?$/);
    const routeIdentifier = match?.[1] || '';
    const venueId = Number(routeIdentifier.match(/^(\d+)(?:-|$)/)?.[1] || 0);

    if (venueId > 0) {
        activateBookingAction(venueId);
        mountVenueActivities(routeIdentifier);
    }
}

function activateBookingAction(venueId) {
    const current = document.querySelector('.venue-booking-action');
    if (!current) return;

    const link = document.createElement('a');
    link.className = current.className;
    link.href = `/events/create/wizard?venue_id=${encodeURIComponent(String(venueId))}`;
    link.setAttribute('aria-label', 'Забронировать');
    link.innerHTML = `
        <i class="ti ti-calendar-plus venue-booking-action__icon" aria-hidden="true"></i>
        <span class="venue-booking-action__label">Забронировать</span>
    `;
    current.replaceWith(link);
}

async function mountVenueActivities(routeIdentifier) {
    const anchor = venuePage.querySelector('.venue-anchor-nav');
    const hero = venuePage.querySelector('.venue-hero');
    if (!hero) return;

    const section = document.createElement('section');
    section.id = 'activities';
    section.className = 'venue-show-section venue-activities';
    section.dataset.venueActivities = '1';
    section.innerHTML = `
        <div class="venue-show-section__heading venue-activities__heading">
            <div>
                <span class="venue-activities__eyebrow">На этой площадке</span>
                <h2>Игры и мероприятия</h2>
            </div>
            <span class="venue-section-state" data-venue-activities-state>Загрузка…</span>
        </div>
        <div class="venue-activities__body" data-venue-activities-body>
            <div class="venue-activities__loading">Загружаем текущие и ближайшие активности…</div>
        </div>
    `;

    if (anchor) anchor.before(section);
    else hero.after(section);

    await loadActivities(section, routeIdentifier);
}

async function loadActivities(section, routeIdentifier) {
    const body = section.querySelector('[data-venue-activities-body]');
    const state = section.querySelector('[data-venue-activities-state]');

    try {
        const response = await fetch(`/venues/${encodeURIComponent(routeIdentifier)}/activities`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(payload.message || 'Не удалось загрузить активности.');

        renderActivities(body, payload, routeIdentifier);
        const count = (payload.current?.length || 0) + (payload.upcoming?.length || 0);
        state.textContent = count ? `${count} активност${count === 1 ? 'ь' : count < 5 ? 'и' : 'ей'}` : 'Пока пусто';
    } catch (error) {
        body.innerHTML = '';
        const message = document.createElement('div');
        message.className = 'venue-activities__empty';
        message.textContent = error.message || 'Не удалось загрузить активности.';
        body.append(message);
        state.textContent = 'Ошибка загрузки';
    }
}

function renderActivities(body, payload, routeIdentifier) {
    body.innerHTML = '';
    const current = Array.isArray(payload.current) ? payload.current : [];
    const upcoming = Array.isArray(payload.upcoming) ? payload.upcoming : [];

    body.append(renderGroup('Сейчас', current, 'Сейчас на площадке ничего не проходит.', true));
    body.append(renderGroup('Ближайшие', upcoming, 'Ближайших мероприятий пока нет.', false));

    current.filter((activity) => activity.is_live && activity.game_id && activity.snapshot_url)
        .forEach((activity) => bindLiveActivity(body, activity, routeIdentifier));
}

function renderGroup(title, activities, emptyText, current) {
    const group = document.createElement('div');
    group.className = `venue-activities__group${current ? ' venue-activities__group--current' : ''}`;

    const heading = document.createElement('div');
    heading.className = 'venue-activities__group-heading';
    const headingTitle = document.createElement('h3');
    headingTitle.textContent = title;
    heading.append(headingTitle);
    if (current && activities.length) {
        const live = document.createElement('span');
        live.className = 'venue-activities__live-indicator';
        live.innerHTML = '<i aria-hidden="true"></i> Сейчас';
        heading.append(live);
    }
    group.append(heading);

    if (!activities.length) {
        const empty = document.createElement('div');
        empty.className = 'venue-activities__empty';
        empty.textContent = emptyText;
        group.append(empty);
        return group;
    }

    const list = document.createElement('div');
    list.className = 'venue-activities__list';
    activities.forEach((activity) => list.append(renderActivity(activity)));
    group.append(list);
    return group;
}

function renderActivity(activity) {
    const card = document.createElement('article');
    card.className = `venue-activity-card${activity.is_live ? ' venue-activity-card--live' : ''}`;
    if (activity.game_id) card.dataset.venueActivityGame = String(activity.game_id);

    const top = document.createElement('div');
    top.className = 'venue-activity-card__top';
    const badge = document.createElement('span');
    badge.className = 'venue-activity-card__badge';
    badge.textContent = activity.type_label || 'Мероприятие';
    const status = document.createElement('span');
    status.className = `venue-activity-card__status${activity.is_live ? ' is-live' : ''}`;
    status.dataset.venueActivityStatus = '1';
    status.textContent = activity.status_label || '';
    top.append(badge, status);
    card.append(top);

    const title = document.createElement('h4');
    title.textContent = activity.title || 'Мероприятие';
    card.append(title);

    if (activity.teams) {
        card.append(renderScore(activity));
    }

    const meta = document.createElement('div');
    meta.className = 'venue-activity-card__meta';
    meta.textContent = activity.is_live
        ? liveTimeLabel(activity.starts_at)
        : dateRangeLabel(activity.starts_at, activity.ends_at, activity.kind === 'tournament');
    card.append(meta);

    const actions = document.createElement('div');
    actions.className = 'venue-activity-card__actions';
    const primary = document.createElement('a');
    primary.className = 'btn btn--secondary btn--sm';
    primary.href = activity.is_live && activity.live_url ? activity.live_url : activity.url;
    primary.textContent = activity.is_live ? 'Смотреть игру' : 'Подробнее';
    actions.append(primary);
    if (activity.is_live && activity.url && activity.live_url && activity.url !== activity.live_url) {
        const eventLink = document.createElement('a');
        eventLink.className = 'fc-link';
        eventLink.href = activity.url;
        eventLink.textContent = 'Страница мероприятия';
        actions.append(eventLink);
    }
    card.append(actions);

    return card;
}

function renderScore(activity) {
    const score = document.createElement('div');
    score.className = 'venue-activity-score';
    ['A', 'B'].forEach((slot, index) => {
        if (index) {
            const divider = document.createElement('span');
            divider.className = 'venue-activity-score__divider';
            divider.textContent = ':';
            score.append(divider);
        }
        const team = activity.teams?.[slot] || {};
        const item = document.createElement('div');
        item.className = 'venue-activity-score__team';
        const name = document.createElement('span');
        name.className = 'venue-activity-score__name';
        name.dataset.venueActivityTeam = slot;
        name.textContent = team.name || `Команда ${slot}`;
        const points = document.createElement('strong');
        points.dataset.venueActivityScore = slot;
        points.textContent = String(team.score ?? 0);
        item.append(name, points);
        score.append(item);
    });
    return score;
}

function bindLiveActivity(root, activity, routeIdentifier) {
    let stopped = false;
    let request = null;
    const gameId = Number(activity.game_id);
    const selector = `[data-venue-activity-game="${gameId}"]`;

    const refresh = async () => {
        if (stopped || request) return;
        const controller = new AbortController();
        request = controller;
        try {
            const response = await fetch(activity.snapshot_url, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: controller.signal,
            });
            const snapshot = await response.json().catch(() => ({}));
            if (!response.ok) return;
            const card = root.querySelector(selector);
            if (!card) return;
            ['A', 'B'].forEach((slot) => {
                const score = card.querySelector(`[data-venue-activity-score="${slot}"]`);
                const name = card.querySelector(`[data-venue-activity-team="${slot}"]`);
                if (score) score.textContent = String(snapshot.scores?.[slot] ?? 0);
                if (name && snapshot.teams?.[slot]?.name) name.textContent = snapshot.teams[slot].name;
            });
            if (snapshot.status?.is_terminal) {
                stopped = true;
                window.clearInterval(timer);
                unsubscribe();
                const section = root.closest('[data-venue-activities]');
                if (section) loadActivities(section, routeIdentifier);
            }
        } catch (_) {
            // Polling and the next broadcast will retry silently.
        } finally {
            if (request === controller) request = null;
        }
    };

    const unsubscribe = subscribePublic(`game.live.${gameId}`, '.game.live.updated', refresh);
    const timer = window.setInterval(refresh, 30000);
}

function liveTimeLabel(value) {
    const date = parseDate(value);
    if (!date) return 'Игра идёт сейчас';
    return `Началась в ${time(date)}`;
}

function dateRangeLabel(startValue, endValue, dateOnly = false) {
    const start = parseDate(startValue);
    const end = parseDate(endValue);
    if (!start) return '';
    if (dateOnly) {
        if (!end || sameDay(start, end)) return date(start);
        return `${date(start)} — ${date(end)}`;
    }
    if (!end) return `${date(start)}, ${time(start)}`;
    if (sameDay(start, end)) return `${date(start)}, ${time(start)}–${time(end)}`;
    return `${date(start)}, ${time(start)} — ${date(end)}, ${time(end)}`;
}

function parseDate(value) {
    if (!value) return null;
    const parsed = new Date(value);
    return Number.isNaN(parsed.getTime()) ? null : parsed;
}

function date(value) {
    return new Intl.DateTimeFormat('ru-RU', { day: '2-digit', month: 'short' }).format(value);
}

function time(value) {
    return new Intl.DateTimeFormat('ru-RU', { hour: '2-digit', minute: '2-digit' }).format(value);
}

function sameDay(a, b) {
    return a.getFullYear() === b.getFullYear()
        && a.getMonth() === b.getMonth()
        && a.getDate() === b.getDate();
}
