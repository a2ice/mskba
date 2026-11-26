import { subscribePublic, subscribePrivate } from '../../../js/realtime.js';
import { affectedDashboardSources, mergeDashboardRefresh, sameDashboardIds } from './dashboard-refresh-sources.js';

const dashboard = document.querySelector('[data-account-dashboard]');

if (dashboard) {
    const source = dashboard.dataset.refreshUrl;
    const header = document.querySelector('[data-app-header][data-notification-user-id]');
    let version = 0;
    let controller = null;
    let liveTimer = null;
    let liveDeadline = 0;
    let liveSources = [];
    let queuedSources = [];
    let running = false;
    let activePartial = false;
    let disposed = false;
    // A revision is delayed only once, not after every Reverb / notification refresh.
    const pending = new Map();

    const clearPending = (id) => {
        const entry = pending.get(id);
        if (!entry) return;
        window.clearInterval(entry.timer);
        entry.current.classList.remove('app-dashboard-widget--refreshing');
        entry.current.removeAttribute('aria-busy');
        entry.counter.remove();
        pending.delete(id);
    };

    const finishPending = (id) => {
        const entry = pending.get(id);
        if (!entry) return;
        const { current, replacement } = entry;
        clearPending(id);
        if (current.isConnected) current.replaceWith(replacement);
    };

    const scheduleReplacement = (id, current, incoming, delaySeconds) => {
        const previous = pending.get(id);
        if (previous?.revision === incoming.dataset.widgetVersion) {
            previous.replacement = incoming;
            return;
        }
        if (previous) clearPending(id);

        const counter = document.createElement('span');
        counter.className = 'app-dashboard-widget__refresh-state';
        counter.setAttribute('role', 'status');
        counter.setAttribute('aria-label', 'Виджет обновляется');
        const entry = {
            revision: incoming.dataset.widgetVersion,
            replacement: incoming,
            current,
            counter,
            deadline: Date.now() + delaySeconds * 1000,
            timer: null,
        };
        pending.set(id, entry);
        current.classList.add('app-dashboard-widget--refreshing');
        current.setAttribute('aria-busy', 'true');
        current.append(counter);
        const tick = () => {
            const seconds = Math.max(0, Math.ceil((entry.deadline - Date.now()) / 1000));
            counter.textContent = String(seconds);
            counter.title = 'Обновление через ' + seconds + ' с';
            if (!seconds) finishPending(id);
        };
        tick();
        entry.timer = window.setInterval(tick, 250);
    };

    const reconcile = (html) => {
        const template = document.createElement('template');
        template.innerHTML = html;
        const oldWidgets = new Map(
            Array.from(dashboard.querySelectorAll('[data-dashboard-widget]'))
                .map((node) => [node.dataset.dashboardWidget, node]),
        );
        const nextChildren = [];
        const seen = new Set();
        for (const incoming of Array.from(template.content.children)) {
            const id = incoming.dataset?.dashboardWidget;
            if (!id) {
                nextChildren.push(incoming);
                continue;
            }
            seen.add(id);
            const current = oldWidgets.get(id);
            const oldPending = pending.get(id);
            if (!current) {
                clearPending(id);
                nextChildren.push(incoming);
                continue;
            }
            if (incoming.dataset.widgetVersion === current.dataset.widgetVersion) {
                if (oldPending) clearPending(id);
                // Dynamic notification counts can change without an admin edit.
                nextChildren.push(current.outerHTML === incoming.outerHTML ? current : incoming);
                continue;
            }
            const delay = Math.max(0, Math.min(60, Number(incoming.dataset.widgetRefreshDelay) || 0));
            // Personal data must not be kept on screen for the admin-edit countdown.
            if (delay === 0 || incoming.dataset.widgetKind === 'dynamic' || current.dataset.widgetKind === 'dynamic') {
                clearPending(id);
                nextChildren.push(incoming);
                continue;
            }
            scheduleReplacement(id, current, incoming, delay);
            nextChildren.push(current);
        }
        for (const id of pending.keys()) {
            if (!seen.has(id)) clearPending(id);
        }
        dashboard.replaceChildren(...nextChildren);
    };

    const refreshFull = async () => {
        const serial = ++version;
        controller?.abort();
        controller = new AbortController();
        try {
            const response = await fetch(source, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                cache: 'no-store',
                signal: controller.signal,
            });
            if (!response.ok) return;
            const data = await response.json();
            if (serial !== version || typeof data.html !== 'string') return;
            if (dashboard.dataset.dashboardOrdering === '1') {
                // A removed, unpublished or audience-restricted widget must
                // disappear immediately even while the user is sorting.
                // When identities are unchanged, defer DOM reconciliation so
                // admin/realtime refreshes cannot undo local drag order.
                const snapshot = document.createElement('template');
                snapshot.innerHTML = data.html;
                const ids = (nodes) => Array.from(nodes)
                    .filter((node) => node.hasAttribute('data-dashboard-widget'))
                    .map((node) => node.dataset.dashboardWidget).sort();
                const oldIds = ids(dashboard.children);
                const newIds = ids(snapshot.content.children);
                if (oldIds.length === newIds.length
                    && oldIds.every((id, index) => id === newIds[index])) {
                    // Preserve drag order but reconcile private data even during a drag.
                    const incomingById = new Map(Array.from(snapshot.content.children)
                        .filter(node => node.hasAttribute('data-dashboard-widget'))
                        .map(node => [node.dataset.dashboardWidget, node]));
                    for (const current of dashboard.querySelectorAll('[data-dashboard-widget]')) {
                        const incoming = incomingById.get(current.dataset.dashboardWidget);
                        if (current.dataset.widgetKind !== 'dynamic' && incoming?.dataset.widgetKind !== 'dynamic') continue;
                        const content = current.querySelector('.app-dashboard-widget__content');
                        const replacement = incoming?.querySelector('.app-dashboard-widget__content');
                        if (incoming && current.hidden !== incoming.hidden) {
                            document.dispatchEvent(new Event('mskba:dashboard-edit-cancel'));
                            reconcile(data.html);
                            return;
                        }
                        if (content && replacement && content.outerHTML !== replacement.outerHTML) content.replaceWith(replacement);
                    }
                    return;
                }

                document.dispatchEvent(new Event('mskba:dashboard-edit-cancel'));
            }
            reconcile(data.html);
        } catch (_error) {
            // Preserve last authorized snapshot; retry on reconnect/focus.
        }
    };

    const refreshPartial = async (keys) => {
        const serial = ++version;
        controller?.abort();
        controller = new AbortController();
        const url = new URL(source, window.location.href);
        for (const key of keys) url.searchParams.append('sources[]', key);
        try {
            const response = await fetch(url, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                cache: 'no-store',
                signal: controller.signal,
            });
            if (serial !== version || controller.signal.aborted || disposed) return true;
            if (!response.ok) return false;
            const data = await response.json();
            if (serial !== version || controller.signal.aborted || disposed) return true;
            if (data.partial !== true || typeof data.html !== 'string') return false;

            const current = Array.from(dashboard.querySelectorAll('[data-dashboard-widget]'));
            if (!sameDashboardIds(current.map(node => node.dataset.dashboardWidget), data.visible_ids)) return false;
            const affected = current.filter(node => node.dataset.widgetKind === 'dynamic' && node.dataset.widgetLive !== '0' && keys.includes(node.dataset.widgetSource));
            const template = document.createElement('template');
            template.innerHTML = data.html;
            const replacements = Array.from(template.content.children);
            if (!sameDashboardIds(affected.map(node => node.dataset.dashboardWidget), replacements.map(node => node.dataset.dashboardWidget))) return false;
            const byId = new Map(replacements.map(node => [node.dataset.dashboardWidget, node]));

            // Validate every replacement before touching the DOM. Changes of
            // configuration, visibility or ACL require a full reconciliation.
            const patches = [];
            for (const node of affected) {
                const incoming = byId.get(node.dataset.dashboardWidget);
                if (!incoming || incoming.dataset.widgetVersion !== node.dataset.widgetVersion
                    || incoming.dataset.widgetSource !== node.dataset.widgetSource || incoming.dataset.widgetKind !== 'dynamic') return false;
                const oldContent = node.querySelector('.app-dashboard-widget__content');
                const newContent = incoming.querySelector('.app-dashboard-widget__content');
                if (!oldContent || !newContent) return false;
                patches.push([node, incoming.hidden, oldContent, newContent]);
            }
            if (dashboard.dataset.dashboardOrdering === '1' && patches.some(([node, hidden]) => node.hidden !== hidden)) {
                document.dispatchEvent(new Event('mskba:dashboard-edit-cancel'));
            }
            for (const [node, hidden, oldContent, newContent] of patches) {
                if (oldContent.outerHTML !== newContent.outerHTML) oldContent.replaceWith(newContent);
                node.hidden = hidden;
            }
            return true;
        } catch (_error) {
            // Abort by a queued full refresh is intentional; the full snapshot
            // supersedes all prior partials. Other failures trigger one full retry.
            return controller.signal.aborted || disposed;
        }
    };

    const drainRefresh = async () => {
        if (running || disposed) return;
        running = true;
        try {
            while (!disposed && (queuedSources === null || queuedSources.length)) {
                const keys = queuedSources;
                queuedSources = [];
                activePartial = keys !== null;
                if (activePartial) {
                    if (!await refreshPartial(keys) && !disposed) queuedSources = null;
                } else {
                    await refreshFull();
                }
                activePartial = false;
            }
        } finally {
            running = false;
            activePartial = false;
        }
    };

    const refresh = (keys = null) => {
        if (disposed || (Array.isArray(keys) && keys.length === 0)) return;
        queuedSources = mergeDashboardRefresh(queuedSources, keys);
        // Access/catalogue changes take precedence over a slow partial fetch.
        if (queuedSources === null && activePartial) controller?.abort();
        void drainRefresh();
    };

    // Coalesce bursts from one transaction, with a bounded wait so sustained
    // updates cannot indefinitely postpone fetching fresh data.
    const scheduleLiveRefresh = (keys = null) => {
        if (Array.isArray(keys) && keys.length === 0) return;
        liveSources = mergeDashboardRefresh(liveSources, keys);
        if (!liveTimer) liveDeadline = Date.now() + 1500;
        window.clearTimeout(liveTimer);
        liveTimer = window.setTimeout(() => {
            liveTimer = null;
            const next = liveSources;
            liveSources = [];
            refresh(next);
        }, Math.max(0, Math.min(250, liveDeadline - Date.now())));
    };

    subscribePublic('account-dashboard', '.dashboard.changed', () => void refresh());
    if (header) {
        const channel = 'users.' + header.dataset.notificationUserId;
        // Legacy notifications also feed static message widgets and other
        // existing portal flows, so retain their full reconciliation.
        subscribePrivate(channel, '.notification.created', () => scheduleLiveRefresh());
        subscribePrivate(channel, '.dashboard.widgets.invalidated', event => {
            const nodes = Array.from(dashboard.querySelectorAll('[data-widget-kind="dynamic"]'));
            const sources = nodes.filter(node => node.dataset.widgetLive !== '0').map(node => node.dataset.widgetSource);
            if (event?.catalog_changed || sources.some(key => !key)) {
                refresh();
                return;
            }
            scheduleLiveRefresh(affectedDashboardSources(event, sources));
        });
    }
    document.addEventListener('mskba:dashboard-refresh-requested', () => void refresh());
    window.addEventListener('mskba:realtime-state', event => {
        if (event.detail?.state === 'connected') void refresh();
    });
    window.addEventListener('focus', () => void refresh());
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) void refresh();
    });
    document.addEventListener('notification:count-changed', () => void refresh());
    const timer = window.setInterval(() => {
        if (!document.hidden) void refresh();
    }, 90000);
    window.addEventListener('pagehide', () => {
        disposed = true;
        queuedSources = [];
        window.clearInterval(timer);
        window.clearTimeout(liveTimer);
        controller?.abort();
        for (const id of pending.keys()) clearPending(id);
    }, { once: true });
}
