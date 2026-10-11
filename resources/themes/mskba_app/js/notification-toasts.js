// Compact, scrollable and persistent message toasts. One card per unread ID;
// removing one means marking it read on the server, never just hiding it.
export const createNotificationToastDock = (region, onCountChange) => {
    if (!region) return null;

    const cards = new Map();
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

    const remove = (id) => {
        const key = String(id);
        cards.get(key)?.remove();
        cards.delete(key);
        region.hidden = cards.size === 0;
    };

    const create = (notification, live = false) => {
        const card = document.createElement('article');
        card.className = 'toast app-message-toast';
        card.dataset.messageToastId = String(notification.id);
        if (live) card.setAttribute('role', 'status');

        const heading = document.createElement('strong');
        heading.className = 'app-message-toast__heading';
        heading.textContent = notification.title || 'Новое сообщение';

        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'app-message-toast__close';
        close.setAttribute('aria-label', 'Отметить сообщение прочитанным');
        close.title = 'Отметить прочитанным';
        close.textContent = '×';
        close.addEventListener('click', async () => {
            if (close.disabled) return;
            close.disabled = true;
            try {
                const response = await fetch(notification.read_url, {
                    method: 'PATCH',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrf,
                    },
                });
                if (!response.ok) throw new Error('Не удалось отметить сообщение прочитанным.');
                const result = await response.json();
                remove(notification.id);
                onCountChange(result.unread_count);
            } catch (error) {
                let description = card.querySelector('.app-message-toast__error');
                if (!description) {
                    description = document.createElement('p');
                    description.className = 'app-message-toast__error';
                    card.append(description);
                }
                description.textContent = error.message || 'Попробуйте ещё раз.';
            } finally {
                close.disabled = false;
            }
        });

        card.append(heading, close);
        if (notification.body) {
            const body = document.createElement('p');
            body.className = 'app-message-toast__body';
            body.textContent = notification.body;
            card.append(body);
        }

        // Never permit javascript: URLs from notification metadata.
        if (notification.href) {
            try {
                const url = new URL(notification.href, window.location.origin);
                if ((url.protocol === 'https:' || url.protocol === 'http:')
                    && url.origin === window.location.origin
                    && url.pathname !== '/account/notifications') {
                    const link = document.createElement('a');
                    link.className = 'app-message-toast__link';
                    link.href = url.href;
                    link.textContent = notification.action_text || 'Открыть';
                    card.append(link);
                }
            } catch {
                // Invalid action URLs are simply omitted.
            }
        }
        return card;
    };

    const add = (notification, live = false) => {
        if (!notification?.id || !notification?.read_url || cards.has(String(notification.id))) return;
        const card = create(notification, live);
        cards.set(String(notification.id), card);
        if (live) {
            region.prepend(card);
            region.scrollTop = 0;
        } else {
            region.append(card);
        }
        region.hidden = false;
    };

    const reconcile = (notifications) => {
        const incoming = new Set(notifications.map((item) => String(item.id)));
        for (const key of cards.keys()) {
            if (!incoming.has(key)) remove(key);
        }
        notifications.forEach((item) => add(item));
    };

    return { add, remove, reconcile, size: () => cards.size };
};
