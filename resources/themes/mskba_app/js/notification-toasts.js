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

export const createNotificationToastDock = (region, onCountChange, options = {}) => {
    if (!region) return null;

    // Keep all unread notifications in memory, but mount at most three cards.
    const notifications = new Map();
    const cards = new Map();
    const pinned = new Map();
    const storageKey = options.userId ? 'mskba:toast-dismissed:' + options.userId : null;
    const restoreDismissed = () => {
        if (!storageKey) return [];
        try {
            const items = JSON.parse(window.sessionStorage.getItem(storageKey) || '[]');
            return Array.isArray(items) ? items.filter(id => /^\d+$/.test(String(id))).slice(-100) : [];
        } catch { return []; }
    };
    const dismissed = new Set(restoreDismissed());
    const storeDismissed = () => {
        if (!storageKey) return;
        try { window.sessionStorage.setItem(storageKey, JSON.stringify([...dismissed].slice(-100))); }
        catch { /* Browser storage may be unavailable. */ }
    };
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

    const mountWaiting = () => {
        for (const [id, notification] of [...pinned, ...notifications]) {
            if (cards.size >= MAX_VISIBLE_TOASTS) break;
            if (cards.has(id) || (dismissed.has(id) && !pinned.has(id))) continue;
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
        dismissed.delete(key);
        storeDismissed();
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
        close.setAttribute('aria-label', 'Скрыть уведомление');
        close.title = 'Скрыть без отметки прочитанным';
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
                options.onRead?.(notification);
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

        close.addEventListener('click', () => {
            pinned.delete(String(notification.id));
            options.onDismiss?.(notification);
            dismissed.add(String(notification.id));
            storeDismissed();
            cards.get(String(notification.id))?.remove();
            cards.delete(String(notification.id));
            mountWaiting();
        });
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
            expand.addEventListener('click', () => options.onExpand(notification));
            card.append(expand);
        }

        const read = document.createElement('button');
        read.type = 'button';
        read.className = 'app-message-toast__read';
        read.textContent = 'Отметить прочитанным';
        read.setAttribute('aria-label', 'Отметить уведомление прочитанным');
        read.addEventListener('click', () => markRead());
        // An already read conversation can remain minimized in its original
        // toast, but there is no new "read" action to offer for that notice.
        read.hidden = Boolean(notification.is_read);
        read.disabled = Boolean(notification.is_read);
        card.append(read);

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
                    link.addEventListener('click', (event) => {
                        // Let browsers handle new tabs / modified clicks normally.
                        if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
                        event.preventDefault();
                        // Navigation is not an acknowledgement. Conversation views
                        // acknowledge when actually read according to their rules.
                        window.location.assign(url.href);
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
        if (cards.size < MAX_VISIBLE_TOASTS && ! dismissed.has(id)) {
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
                dismissed.delete(id);
            }
        }
        storeDismissed();
        unread.forEach((item) => add(item));
        mountWaiting();
    };

    const pin = (notification) => {
        if (!notification?.id || !notification?.read_url) return;
        const id = String(notification.id);
        pinned.set(id, notification);
        dismissed.delete(id);
        storeDismissed();
        // Prefer the restored original toast to other waiting unread toasts.
        if (!cards.has(id) && cards.size >= MAX_VISIBLE_TOASTS) {
            const evict = [...cards.keys()].reverse().find(other => !pinned.has(other));
            if (evict) {
                cards.get(evict)?.remove();
                cards.delete(evict);
            }
        }
        if (cards.has(id)) {
            // The expanded window may have acknowledged this notification.
            // Keep the original toast, but reflect its CURRENT read status.
            const read = cards.get(id).querySelector('.app-message-toast__read');
            if (read) {
                read.hidden = Boolean(notification.is_read);
                read.disabled = Boolean(notification.is_read);
                read.textContent = 'Отметить прочитанным';
            }
        } else {
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

    return {
        add, remove, reconcile, pin, unpin,
        size: () => cards.size,
    };
};
