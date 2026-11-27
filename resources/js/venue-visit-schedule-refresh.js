import { subscribePublic } from './realtime.js';
import { markVisitRefreshFailed } from './venue-visit-schedule-state.js';

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
    let deadlineTimer;
    let unsubscribe = () => {};
    const controller = new AbortController();
    const deadline = Date.now() + 125_000;

    const stop = () => {
        if (stopped) return;
        stopped = true;
        window.clearTimeout(timer);
        window.clearTimeout(deadlineTimer);
        controller.abort();
        unsubscribe();
    };
    const failPending = () => {
        if (stopped) return;
        markVisitRefreshFailed(section, pending);
        stop();
    };
    const plan = () => {
        window.clearTimeout(timer);
        if (!stopped) timer = window.setTimeout(check, 5000);
    };

    async function check() {
        if (stopped || checking) return;
        if (Date.now() > deadline) {
            failPending();
            return;
        }
        checking = true;
        try {
            const response = await fetch(url, {
                credentials: 'same-origin', cache: 'no-store',
                signal: controller.signal,
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
                    signal: controller.signal,
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
                // The fragment can observe a newer state than the status
                // request (another visitor may have started a refresh).
                const rendered = JSON.parse(section.dataset.refreshStates || '{}');
                // A pending marker can expire without a worker completing.
                // Treat a still-stale snapshot as a failed refresh, not success.
                markVisitRefreshFailed(section, new Set(settled.filter(id => rendered[id]?.state === 'stale')));
                for (const id of settled) {
                    if (rendered[id]?.state !== 'updating') pending.delete(id);
                }
                for (const id of Object.keys(rendered)) {
                    if (rendered[id].state === 'updating') pending.add(id);
                }
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
    // Independent deadline also releases the UI if a fetch hangs.
    deadlineTimer = window.setTimeout(failPending, Math.max(0, deadline - Date.now()));
    plan();
}

initVisitScheduleRefresh();
