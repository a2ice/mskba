// A maximum of three unread toasts are visible; the remaining unread items
// wait in the dock until a visible item is marked as read.
export const MAX_VISIBLE_TOASTS = 3;
export const MAX_TOAST_BODY_LENGTH = 50;

export const truncateToastMessage = (value) => {
    const text = String(value ?? '').trim();
    const characters = typeof Intl.Segmenter === 'function'
        ? Array.from(new Intl.Segmenter('ru', { granularity: 'grapheme' }).segment(text), (item) => item.segment)
        : Array.from(text);

    if (characters.length <= MAX_TOAST_BODY_LENGTH) return text;
    return characters.slice(0, MAX_TOAST_BODY_LENGTH - 3).join('') + '...';
};

export const createNotificationToastDock = (region, onCountChange) => {
    if (!region) return null;

    // Keep all unread notifications in memory, but mount at most three cards.
    const notifications = new Map();
    const cards = new Map();
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

    const mountWaiting = () => {
        for (const [id, notification] of notifications) {
            if (cards.size >= MAX_VISIBLE_TOASTS) break;
            if (cards.has(id)) continue;
            const card = create(notification);
            cards.set(id, card);
            region.append(card);
        }
        region.hidden = cards.size === 0;
    };

    const remove = (id) => {
        const key = String(id);
        notifications.delete(key);
        cards.get(key)?.remove();
        cards.delete(key);
        mountWaiting();
    };

    const create = (notification, live = false) => {
        const card = document.createElement('article');
        card.className = 'toast app-message-toast';
        card.dataset.messageToastId = String(notification.id);
        if (live) card.setAttribute('role', 'status');

        // Colors are assigned by the server's UserNotificationTypeEnum.
        if (/^#[0-9a-f]{6}$/i.test(notification.type_color || '')) {
            card.style.setProperty('--toast-accent', notification.type_color);
        }

        const heading = document.createElement('strong');
        heading.className = 'app-message-toast__heading';
        heading.textContent = notification.title || 'Новое сообщение';

        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'app-message-toast__close';
        close.setAttribute('aria-label', 'Отметить сообщение прочитанным');
        close.title = 'Отметить прочитанным';
        close.textContent = '×';

        let markingAsRead = false;
        const markRead = async () => {
            if (markingAsRead) return false;
            markingAsRead = true;
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
                onCountChange?.(result.unread_count);
                return true;
            } catch (error) {
                let description = card.querySelector('.app-message-toast__error');
                if (!description) {
                    description = document.createElement('p');
                    description.className = 'app-message-toast__error';
                    card.append(description);
                }
                description.textContent = error.message || 'Попробуйте ещё раз.';
                return false;
            } finally {
                markingAsRead = false;
                close.disabled = false;
            }
        };

        close.addEventListener('click', () => markRead());
        card.append(heading, close);

        if (notification.body) {
            const body = document.createElement('p');
            body.className = 'app-message-toast__body';
            body.textContent = truncateToastMessage(notification.body);
            body.setAttribute('aria-label', notification.body);
            body.title = notification.body;
            card.append(body);
        }

        // Never permit external or javascript: URLs from notification metadata.
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
                    link.addEventListener('click', async (event) => {
                        // Let browsers handle new tabs / modified clicks normally.
                        if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
                        event.preventDefault();
                        // Navigate only after the notification is successfully read.
                        if (await markRead()) window.location.assign(url.href);
                    });
                    card.append(link);
                }
            } catch {
                // Invalid action URLs are simply omitted.
            }
        }
        return card;
    };

    const add = (notification, live = false) => {
        if (!notification?.id || !notification?.read_url) return;
        const id = String(notification.id);
        if (notifications.has(id)) return;
        notifications.set(id, notification);
        if (cards.size < MAX_VISIBLE_TOASTS) {
            const card = create(notification, live);
            cards.set(id, card);
            if (live) {
                region.prepend(card);
                region.scrollTop = 0;
            } else {
                region.append(card);
            }
            region.hidden = false;
        }
    };

    const reconcile = (unread) => {
        const incoming = new Set(unread.map((item) => String(item.id)));
        for (const id of [...notifications.keys()]) {
            if (!incoming.has(id)) {
                notifications.delete(id);
                cards.get(id)?.remove();
                cards.delete(id);
            }
        }
        unread.forEach((item) => add(item));
        mountWaiting();
    };

    return { add, remove, reconcile, size: () => cards.size };
};
