import { subscribePrivate } from '../../../../js/realtime.js';

const region = document.querySelector('[data-notification-toasts]');

if (region) {
    const visibleLimit = 3;
    const pending = [];
    const knownIds = new Set();
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const updateCount = (count) => {
        document.querySelectorAll('[data-notification-count]').forEach((badge) => {
            badge.textContent = count > 9 ? '...' : String(count);
            badge.classList.toggle('d-none', count < 1);
            badge.setAttribute('aria-label', `Новые уведомления: ${count}`);
        });
        document.querySelector('[data-notification-read-all]')?.classList.toggle('d-none', count < 1);
    };

    const updateNotificationCard = (notificationId) => {
        const card = document.querySelector(`[data-notification-card="${notificationId}"]`);
        if (!card) return;

        card.classList.remove('is-new');
        card.classList.add('is-read');
        card.querySelector('[data-notification-read-action]')?.remove();

        const status = card.querySelector('.account-notification-badge--status');
        if (status) {
            status.classList.remove('is-new');
            status.classList.add('is-read');
            status.title = 'Прочитанное';
            status.setAttribute('aria-label', 'Прочитанное');

            const icon = status.querySelector('i');
            icon?.classList.remove('ti-bell-ringing');
            icon?.classList.add('ti-check');
        }
    };

    const request = async (url, method, body = null) => {
        const response = await fetch(url, {
            method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
            },
            body: body ? JSON.stringify(body) : null,
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(payload.message || 'Не удалось выполнить действие.');
        return payload;
    };

    const removeToast = (toast) => {
        toast.classList.add('is-leaving');
        window.setTimeout(() => {
            knownIds.delete(String(toast.dataset.notificationId));
            toast.remove();
            pump();
            synchronize();
        }, 190);
    };

    const showError = (toast, error) => {
        let message = toast.querySelector('.notification-toast__error');
        if (!message) {
            message = document.createElement('p');
            message.className = 'notification-toast__error';
            toast.append(message);
        }
        message.textContent = error.message || 'Не удалось выполнить действие.';
    };

    const markRead = async (notification, toast) => {
        const payload = await request(notification.read_url, 'PATCH');
        updateCount(payload.unread_count ?? 0);
        updateNotificationCard(notification.id);
        removeToast(toast);
    };

    const createToast = (notification) => {
        const toast = document.createElement('article');
        toast.className = 'notification-toast';
        toast.dataset.notificationId = notification.id;

        const link = document.createElement('a');
        link.className = 'notification-toast__link';
        link.href = notification.href;
        const title = document.createElement('strong');
        title.className = 'notification-toast__title';
        title.textContent = notification.title;
        const body = document.createElement('span');
        body.className = 'notification-toast__body';
        body.textContent = notification.body;
        link.append(title);

        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'notification-toast__close';
        close.setAttribute('aria-label', 'Отметить прочитанным');
        close.textContent = '×';
        close.addEventListener('click', () => markRead(notification, toast).catch((error) => showError(toast, error)));

        toast.append(link, body, close);
        if (notification.actions?.length) {
            const actions = document.createElement('div');
            actions.className = 'notification-toast__actions';
            notification.actions.forEach((action) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = `btn btn--${action.variant === 'primary' ? 'primary' : 'secondary'} btn--sm`;
                button.textContent = action.label;
                button.addEventListener('click', async () => {
                    button.disabled = true;
                    try {
                        await request(action.url, action.method, { decision: action.key });
                        await markRead(notification, toast);
                    } catch (error) {
                        button.disabled = false;
                        showError(toast, error);
                    }
                });
                actions.append(button);
            });
            toast.append(actions);
        }

        return toast;
    };

    const pump = () => {
        while (region.children.length < visibleLimit && pending.length) {
            region.append(createToast(pending.shift()));
        }
    };

    const enqueue = (notification) => {
        if (!notification?.id || knownIds.has(String(notification.id))) return;

        knownIds.add(String(notification.id));
        pending.push(notification);
        pump();
    };

    const synchronize = () => request(region.dataset.notificationSyncUrl, 'GET')
        .then((payload) => {
            updateCount(Number(payload.unread_count || 0));
            (payload.notifications || []).forEach(enqueue);
        })
        .catch(() => {
            // Realtime delivery remains available if synchronization fails.
        });

    subscribePrivate(`users.${region.dataset.notificationUserId}`, '.notification.created', (payload) => {
        updateCount(Number(payload.unread_count || 0));
        enqueue(payload.notification);
    });

    synchronize();
}
