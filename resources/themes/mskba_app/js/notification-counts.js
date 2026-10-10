// One realtime unread count for the shared avatar and both account sidebars.
// The backend is authoritative; the normal sync endpoint heals reconnects,
// notification reads in other tabs and temporarily unavailable Reverb.
import { subscribePrivate } from '../../../js/realtime.js';

const header = document.querySelector('[data-app-header][data-notification-user-id]');

if (header) {
    const countBadge = header.querySelector('.app-user-avatar__unread[data-notification-count]');
    // The visual value can be ∞; initialize from the uncapped server count.
    let latestCount = Math.max(0, Number(countBadge?.dataset.unreadCount || 0) || 0);
    let revision = 0;

    const render = (value) => {
        const count = Number(value);
        if (!Number.isFinite(count) || count < 0) return;
        latestCount = Math.floor(count);
        revision += 1;
        document.querySelectorAll('[data-notification-count]').forEach((badge) => {
            badge.textContent = badge === countBadge
                ? (latestCount > 9 ? '∞' : String(latestCount))
                : (latestCount > 99 ? '99+' : String(latestCount));
            if (badge === countBadge) badge.dataset.unreadCount = String(latestCount);
            badge.hidden = latestCount === 0;
            badge.setAttribute('aria-label', `Непрочитанных уведомлений: ${latestCount}`);
        });
    };

    const synchronize = async () => {
        const startedAt = revision;
        try {
            const response = await fetch(header.dataset.notificationSyncUrl, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!response.ok) return;
            const data = await response.json();
            // A push or newer update occurred during fetch: do not overwrite it.
            if (startedAt === revision) render(data.unread_count);
        } catch {
            // Keep the server-rendered count when the network is unavailable.
        }
    };

    subscribePrivate(`users.${header.dataset.notificationUserId}`, '.notification.created', (payload) => {
        render(payload?.unread_count);
    });
    subscribePrivate(`users.${header.dataset.notificationUserId}`, '.notification.count-updated', (payload) => {
        render(payload?.unread_count);
    });

    document.addEventListener('mskba:account-sidebar-updated', () => render(latestCount));
    document.addEventListener('notification:count-changed', (event) => render(event.detail?.unread_count));
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) void synchronize();
    });
    window.addEventListener('focus', () => void synchronize());
    window.addEventListener('mskba:realtime-state', (event) => {
        if (event.detail?.state === 'connected') void synchronize();
    });

    // Also pick up notifications delivered between HTML rendering and the WS subscription.
    void synchronize();
}
