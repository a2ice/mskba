import { subscribePublic, subscribePrivate } from '../../../js/realtime.js';

const dashboard = document.querySelector('[data-account-dashboard]');

if (dashboard) {
    const source = dashboard.dataset.refreshUrl;
    const header = document.querySelector('[data-app-header][data-notification-user-id]');
    let version = 0;
    let controller = null;

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
            dashboard.innerHTML = data.html;
        } catch (_error) {
            // Keep the last successfully authorized snapshot; retry on reconnect/focus.
        }
    };

    subscribePublic('account-dashboard', '.dashboard.changed', () => void refresh());
    if (header) subscribePrivate(`users.${header.dataset.notificationUserId}`,
        '.notification.created', () => void refresh());
    window.addEventListener('mskba:realtime-state', event => {
        if (event.detail?.state === 'connected') void refresh();
    });
    window.addEventListener('focus', () => void refresh());
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) void refresh();
    });
    document.addEventListener('notification:count-changed', () => void refresh());
    // Reconcile missed admin changes when Reverb is unavailable, without reload.
    const timer = window.setInterval(() => {
        if (!document.hidden) void refresh();
    }, 90000);
    window.addEventListener('pagehide', () => {
        window.clearInterval(timer);
        controller?.abort();
    }, { once: true });
}
