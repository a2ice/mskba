// One realtime unread count for the shared avatar and both account sidebars.
// The backend is authoritative; the normal sync endpoint heals reconnects,
// notification reads in other tabs and temporarily unavailable Reverb.
import { subscribePrivate } from '../../../js/realtime.js';
import { createNotificationToastDock } from './notification-toasts.js';
import { createNotificationPopover, isMessagesPage, isVisibleCurrentThreadToast } from './notification-popover.js';

const header = document.querySelector('[data-app-header][data-notification-user-id]');

if (header) {
    const countBadge = header.querySelector('.app-user-avatar__unread[data-notification-count]');
    // The visual value can be ∞; initialize from the uncapped server count.
    let latestCount = Math.max(0, Number(countBadge?.dataset.unreadCount || 0) || 0);
    let revision = 0;
    let syncSerial = 0;
    const toastAlreadyVisible = (notification) => {
        const viewport = document.querySelector('[data-chat-root] [data-chat-scroll]');
        const atLatest = viewport
            && viewport.scrollHeight - viewport.scrollTop - viewport.clientHeight < 100
            && !document.hidden && document.hasFocus();
        return isVisibleCurrentThreadToast(
            notification, window.location.pathname, window.location.origin, atLatest,
        );
    };
    let dock = null;
    const popover = createNotificationPopover(header.dataset.notificationUserId, {
        onMinimize: (notice) => dock?.pin(notice),
        onExpand: (notice) => dock?.unpin(notice.id),
        onClose: (notice) => { if (notice) dock?.unpin(notice.id); },
    });
    dock = createNotificationToastDock(document.querySelector('[data-message-toast-region]'), (count) => {
        render(count);
        void synchronize();
    }, {
        canExpand: () => !isMessagesPage(window.location.pathname),
        onExpand: (notification) => popover?.open(notification),
        onDismiss: (notification) => {
            // Dismissing a minimized original toast also closes its saved dialog.
            if (popover?.isMinimized?.(notification.id)) popover.close();
        },
        onRead: (notification) => {
            // Explicit read on a minimized toast also ends its temporary
            // persistence. It must not silently reappear on the next page.
            if (popover?.isMinimized?.(notification.id)) popover.close();
        },
        userId: header.dataset.notificationUserId,
    });

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
        const thisSync = ++syncSerial;
        const notifications = [];
        let beforeId = null;
        let unreadCount = null;
        const seenCursors = new Set();
        try {
            do {
                const url = new URL(header.dataset.notificationSyncUrl, window.location.origin);
                if (beforeId !== null) url.searchParams.set('before_id', String(beforeId));
                const response = await fetch(url, {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!response.ok) return;
                const data = await response.json();
                // A newer WebSocket push or a newer sync superseded this response.
                if (thisSync !== syncSerial || startedAt !== revision) return;
                unreadCount = data.unread_count;
                notifications.push(...(data.notifications || []));
                const next = data.next_before_id;
                if (!next || seenCursors.has(String(next))) break;
                seenCursors.add(String(next));
                beforeId = next;
            } while (true);
            if (thisSync !== syncSerial || startedAt !== revision) return;
            dock?.reconcile(notifications.filter(notification => !toastAlreadyVisible(notification)));
            popover?.restore(notifications);
            render(unreadCount);
        } catch {
            // Preserve existing unread count and toasts when offline.
        }
    };

    subscribePrivate(`users.${header.dataset.notificationUserId}`, '.notification.created', (payload) => {
        render(payload?.unread_count);
        if (!toastAlreadyVisible(payload?.notification)) dock?.add(payload?.notification, true);
        // Reconcile any missed events with the authoritative list.
        void synchronize();
    });
    subscribePrivate(`users.${header.dataset.notificationUserId}`, '.notification.count-updated', (payload) => {
        render(payload?.unread_count);
        void synchronize();
    });

    document.addEventListener('mskba:account-sidebar-updated', () => render(latestCount));
    document.addEventListener('notification:count-changed', (event) => {
        render(event.detail?.unread_count);
        void synchronize();
    });
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
