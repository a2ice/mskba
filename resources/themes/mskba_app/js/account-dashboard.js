import { subscribePublic, subscribePrivate } from '../../../js/realtime.js';

const dashboard = document.querySelector('[data-account-dashboard]');

if (dashboard) {
    const source = dashboard.dataset.refreshUrl;
    const header = document.querySelector('[data-app-header][data-notification-user-id]');
    let version = 0;
    let controller = null;
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
            if (delay === 0) {
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

    const refresh = async () => {
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
            reconcile(data.html);
        } catch (_error) {
            // Preserve last authorized snapshot; retry on reconnect/focus.
        }
    };

    subscribePublic('account-dashboard', '.dashboard.changed', () => void refresh());
    if (header) subscribePrivate('users.' + header.dataset.notificationUserId,
        '.notification.created', () => void refresh());
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
        window.clearInterval(timer);
        controller?.abort();
        for (const id of pending.keys()) clearPending(id);
    }, { once: true });
}
