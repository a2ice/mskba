import { subscribePublic } from './realtime.js';

// Only a page that actually queued (or joined) a refresh subscribes/polls.
// No browser request initiates a third-party read; the endpoint is read-only.
function initVisitScheduleRefresh() {
    let section = document.querySelector('[data-venue-schedule-refresh]');
    if (!section) return;

    let initial;
    try {
        initial = JSON.parse(section.dataset.refreshStates || '{}');
    } catch {
        return;
    }
    const pending = new Set(Object.keys(initial).filter(id => initial[id].state === 'updating'));
    if (!pending.size) return;

    const url = new URL(section.dataset.refreshStatusUrl, window.location.href);
    if (section.dataset.refreshCourt) url.searchParams.set('court', section.dataset.refreshCourt);
    const fragmentUrl = new URL(window.location.href);
    fragmentUrl.searchParams.set('schedule_refresh_fragment', '1');
    const venueId = section.dataset.venueId;
    let checking = false;
    let stopped = false;
    let timer;
    let unsubscribe = () => {};
    const deadline = Date.now() + 125_000;

    const stop = () => {
        stopped = true;
        window.clearTimeout(timer);
        unsubscribe();
    };
    const plan = () => {
        window.clearTimeout(timer);
        if (!stopped) timer = window.setTimeout(check, 5000);
    };

    async function check() {
        if (stopped || checking) return;
        if (Date.now() > deadline) {
            for (const message of section.querySelectorAll('[data-venue-refresh-message]')) {
                if (message.textContent.includes('Обновляем расписание')) {
                    message.textContent = 'Расписание пока не удалось обновить. Показаны последние сохранённые данные.';
                }
            }
            stop();
            return;
        }
        checking = true;
        try {
            const response = await fetch(url, {
                credentials: 'same-origin', cache: 'no-store',
                headers: { Accept: 'application/json' },
            });
            if (!response.ok || stopped) throw new Error('Refresh status unavailable');
            const { courts } = await response.json();
            const settled = [...pending].filter(id => courts[id]?.state !== 'updating');
            if (settled.length) {
                // Render just the existing schedule partial server-side; no
                // reconstructed prices, time intervals or stale booking buttons.
                const fragment = await fetch(fragmentUrl, {
                    credentials: 'same-origin', cache: 'no-store',
                    headers: { Accept: 'text/html' },
                });
                if (!fragment.ok || stopped) throw new Error('Refresh fragment unavailable');
                const doc = new DOMParser().parseFromString(await fragment.text(), 'text/html');
                const next = doc.getElementById(section.id);
                if (!next || stopped) throw new Error('Refresh fragment invalid');
                // Keep the legacy modal instance and its close handlers if
                // it is currently mounted. App booking dialogs are outside the fragment.
                const oldModal = section.querySelector('[data-venue-day-modal]');
                const newModal = next.querySelector('[data-venue-day-modal]');
                if (oldModal && newModal) newModal.replaceWith(oldModal);
                section.replaceWith(next);
                section = next;
                document.dispatchEvent(new CustomEvent('mskba:venue-schedule-replaced'));
                for (const id of settled) pending.delete(id);
            }
            if (!pending.size) stop();
        } catch {
            // Reverb loss, transient HTTP errors and rate limiting use the
            // same bounded retry, never trigger another provider request.
        } finally {
            checking = false;
            plan();
        }
    }

    try {
        unsubscribe = subscribePublic('venue-schedule.' + venueId, '.schedule.refreshed', () => void check());
    } catch {
        // A missing public Reverb configuration is not fatal.
    }
    window.addEventListener('pagehide', stop, { once: true });
    plan();
}

initVisitScheduleRefresh();
