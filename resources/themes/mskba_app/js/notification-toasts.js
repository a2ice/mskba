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

// Share the idempotent PATCH between compact and expanded notifications.
const reading = new Map();
export async function acknowledgeNotification(notice, csrf) {
    if (notice.is_read) return null;
    const url = notice.read_url;
    if (!url) throw new Error('Не удалось отметить сообщение прочитанным.');
    if (!reading.has(url)) {
        const request = (async () => {
            const response = await fetch(url, {
                method: 'PATCH', credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf },
            });
            if (!response.ok) throw new Error('Не удалось отметить сообщение прочитанным.');
            return response.json();
        })();
        reading.set(url, request);
    }
    const request = reading.get(url);
    try {
        const result = await request;
        notice.is_read = true;
        return result;
    } finally {
        if (reading.get(url) === request) reading.delete(url);
    }
}

export const createNotificationToastDock = (region, onCountChange, options = {}) => {
    if (!region) return null;

    // Keep all unread notifications in memory, but mount at most three cards.
    const notifications = new Map();
    const cards = new Map();
    const pinned = new Map();
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

    const mountWaiting = () => {
        for (const [id, notification] of [...pinned, ...notifications]) {
            if (cards.size >= MAX_VISIBLE_TOASTS) break;
            if (cards.has(id)) continue;
            const card = create(notification);
            cards.set(id, card);
            region.append(card);
        }
        // User actions share this dock but are not unread notifications.
        region.hidden = cards.size === 0 && !region.querySelector('.app-action-toast');
    };

    const remove = (id) => {
        const key = String(id);
        notifications.delete(key);
        pinned.delete(key);
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
        close.setAttribute('aria-label', 'Закрыть уведомление');
        close.title = 'Закрыть уведомление';
        close.textContent = '×';

        let markingAsRead = false;
        const markRead = async () => {
            if (markingAsRead) return false;
            markingAsRead = true;
            close.disabled = true;
            try {
                const current = pinned.get(String(notification.id)) || notification;
                const result = await acknowledgeNotification(current, csrf);
                remove(notification.id);
                options.onRead?.(current);
                if (result) onCountChange?.(result.unread_count);
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

        close.addEventListener('click', markRead);
        card.append(heading, close);

        if (notification.body) {
            const body = document.createElement('p');
            body.className = 'app-message-toast__body';
            body.textContent = truncateToastMessage(notification.body);
            body.setAttribute('aria-label', notification.body);
            body.title = notification.body;
            card.append(body);
        }

        if (options.canExpand?.(notification) !== false && typeof options.onExpand === 'function') {
            const expand = document.createElement('button');
            expand.type = 'button';
            expand.className = 'app-message-toast__expand';
            expand.textContent = 'Развернуть';
            expand.setAttribute('aria-label', 'Развернуть уведомление');
            expand.addEventListener('click', () => options.onExpand(pinned.get(String(notification.id)) || notification));
            card.append(expand);
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
        if (notifications.has(id) || pinned.get(id)?.is_read || notification.is_read) return;
        notifications.set(id, notification);
        // A freshly delivered notification must not be hidden behind three
        // older unread toasts. Make room without marking anything as read.
        if (live && cards.size >= MAX_VISIBLE_TOASTS) {
            const displaced = [...cards.keys()].reverse().find((other) => !pinned.has(other));
            if (displaced) {
                cards.get(displaced)?.remove();
                cards.delete(displaced);
            }
        }
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
            if (!incoming.has(id) && !pinned.has(id)) {
                notifications.delete(id);
                cards.get(id)?.remove();
                cards.delete(id);
            }
        }
        unread.forEach((item) => add(item));
        mountWaiting();
    };

    const pin = (notification) => {
        if (!notification?.id || !notification?.read_url) return;
        const id = String(notification.id);
        pinned.set(id, notification);
        if (notification.is_read) notifications.delete(id);
        // Prefer the restored original toast to other waiting unread toasts.
        if (!cards.has(id) && cards.size >= MAX_VISIBLE_TOASTS) {
            const evict = [...cards.keys()].reverse().find(other => !pinned.has(other));
            if (evict) {
                cards.get(evict)?.remove();
                cards.delete(evict);
            }
        }
        if (!cards.has(id)) {
            const card = create(notification);
            cards.set(id, card);
            region.prepend(card);
        }
        region.hidden = false;
    };
    const unpin = (id) => {
        const key = String(id);
        if (!pinned.delete(key)) return;
        if (!notifications.has(key)) {
            cards.get(key)?.remove();
            cards.delete(key);
        }
        mountWaiting();
    };

    const acknowledge = (notice) => {
        const id = String(notice.id);
        notifications.delete(id);
        if (pinned.has(id)) {
            pinned.set(id, notice);
            return;
        }
        cards.get(id)?.remove();
        cards.delete(id);
        mountWaiting();
    };

    return {
        add, remove, reconcile, pin, unpin, acknowledge,
        size: () => cards.size,
    };
};
