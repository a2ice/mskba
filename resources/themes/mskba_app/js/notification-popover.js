import { subscribePrivate } from '../../../js/realtime.js';

export const conversationFromHref = (href, origin) => {
    try {
        const url = new URL(href, origin);
        if (url.origin !== origin || url.search || url.hash) return null;
        const match = url.pathname.match(/^\/account\/notifications\/threads\/(support|direct)\/([1-9]\d*)\/?$/);
        return match ? { kind: match[1], id: Number(match[2]) } : null;
    } catch { return null; }
};
const safeHref = (href) => {
    try {
        const url = new URL(href, window.location.origin);
        return url.origin === window.location.origin && /^https?:$/.test(url.protocol) ? url.href : null;
    } catch { return null; }
};
const session = {
    get(key) { try { return window.sessionStorage.getItem(key); } catch { return null; } },
    set(key, val) { try { window.sessionStorage.setItem(key, val); } catch { /* unavailable */ } },
    del(key) { try { window.sessionStorage.removeItem(key); } catch { /* unavailable */ } },
};
export const isMessagesPage = (path) => /^\/account\/notifications(?:\/|$)/.test(path);

export const createNotificationPopover = (userId) => {
    if (!userId || isMessagesPage(window.location.pathname)) return null;
    const key = 'mskba:notification-popover:' + userId;
    const placeKey = key + ':position';
    const root = document.createElement('section');
    root.className = 'app-notification-popover';
    root.hidden = true;
    root.setAttribute('role', 'dialog');
    root.setAttribute('aria-label', 'Уведомление MSKBA');
    root.innerHTML = '<header class="app-notification-popover__header" data-popover-drag>'
        + '<span aria-hidden="true" class="app-notification-popover__grip">⠿</span>'
        + '<strong data-popover-title>Уведомление</strong>'
        + '<button type="button" data-popover-minimize aria-label="Свернуть окно">−</button>'
        + '<button type="button" data-popover-close aria-label="Закрыть окно">×</button></header>'
        + '<div class="app-notification-popover__content" data-popover-content></div>'
        + '<footer class="app-notification-popover__footer"><a data-popover-original href="/account/notifications">Открыть полностью</a>'
        + '<button type="button" data-popover-read>Отметить прочитанным</button></footer>';
    const backdrop = document.createElement('div');
    backdrop.className = 'app-notification-popover__backdrop';
    backdrop.hidden = true;
    backdrop.setAttribute('aria-hidden', 'true');
    document.body.append(backdrop, root);
    const heading = root.querySelector('[data-popover-title]');
    const content = root.querySelector('[data-popover-content]');
    const original = root.querySelector('[data-popover-original]');
    const read = root.querySelector('[data-popover-read]');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    let active = null;
    let descriptor = null;
    let contract = null;
    let unsubscribe = null;
    let polling = null;
    let sending = false;
    let syncing = false;
    let restoreFocus = null;

    const draftKey = () => descriptor ? key + ':draft:' + descriptor.kind + ':' + descriptor.id : null;
    const keepDraft = () => {
        const input = content.querySelector('[data-popover-input]');
        if (input && draftKey()) session.set(draftKey(), input.value.slice(0, 2000));
    };
    const stop = () => {
        unsubscribe?.(); unsubscribe = null;
        if (polling !== null) clearInterval(polling);
        polling = null;
    };
    const close = () => {
        keepDraft();
        stop();
        active = null;
        descriptor = null;
        contract = null;
        root.hidden = true;
        backdrop.hidden = true;
        document.body.classList.remove('app-notification-popover-open');
        content.replaceChildren();
        session.del(key);
        restoreFocus?.focus?.();
    };
    backdrop.addEventListener('click', close);
    root.querySelector('[data-popover-close]').addEventListener('click', close);
    root.querySelector('[data-popover-minimize]').addEventListener('click', close);

    const showError = (message) => {
        let node = content.querySelector('[data-popover-error]');
        if (!node) {
            node = document.createElement('p');
            node.dataset.popoverError = '';
            node.className = 'app-notification-popover__error';
            content.append(node);
        }
        node.textContent = message || '';
    };
    const accessLost = () => {
        stop();
        contract = null;
        content.replaceChildren();
        const message = document.createElement('p');
        message.textContent = 'Доступ к переписке изменился. История скрыта.';
        content.append(message);
        original.href = '/account/notifications';
    };
    const jsonGet = (url) => fetch(url, {
        credentials: 'same-origin', cache: 'no-store',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });

    const history = async (older = false) => {
        if (!contract || root.hidden) return;
        const requested = contract;
        const log = content.querySelector('[data-popover-log]');
        const previous = content.querySelector('[data-popover-older]');
        if (!log) return;
        if (older && !previous?.dataset.before) return;
        const url = new URL(contract.history_url, window.location.origin);
        if (older) url.searchParams.set('before', previous.dataset.before);
        const response = await jsonGet(url);
        if (contract !== requested || root.hidden) return;
        if (response.status === 403 || response.status === 404) { accessLost(); return; }
        if (!response.ok) throw new Error('Не удалось загрузить историю.');
        const data = await response.json();
        if (contract !== requested || root.hidden) return;
        const template = document.createElement('template');
        // History HTML is server-owned Blade output with escaped message bodies.
        template.innerHTML = data.html || '';
        const ids = new Set([...log.querySelectorAll('[data-chat-entry]')].map(el => el.dataset.chatEntry));
        const fresh = [...template.content.children].filter(el => !ids.has(el.dataset.chatEntry));
        const oldHeight = log.scrollHeight, oldTop = log.scrollTop;
        const nearBottom = oldHeight - oldTop - log.clientHeight < 110;
        if (older) {
            log.prepend(...fresh);
            log.scrollTop = oldTop + log.scrollHeight - oldHeight;
        } else {
            log.append(...fresh);
            if (nearBottom || oldHeight === 0) log.scrollTop = log.scrollHeight;
        }
        if (previous) {
            if (older || !previous.dataset.before) previous.dataset.before = data.next_before || '';
            previous.hidden = !previous.dataset.before;
        }
    };

    const state = async () => {
        if (!contract || syncing || root.hidden) return;
        syncing = true;
        const requested = contract;
        try {
            const response = await jsonGet(requested.state_url);
            if (contract !== requested || root.hidden) return;
            if (response.status === 403 || response.status === 404) { accessLost(); return; }
            if (!response.ok) return;
            const data = await response.json();
            if (contract !== requested || root.hidden) return;
            const canSend = Boolean(data.can_send);
            const form = content.querySelector('[data-popover-form]');
            const status = content.querySelector('[data-popover-status]');
            if (form) form.hidden = !canSend;
            if (status) {
                status.hidden = canSend;
                status.textContent = canSend ? '' : descriptor?.kind === 'support' && data.status === 'closed'
                    ? 'Обращение закрыто. История доступна для чтения.\nПричина: ' + (data.close_reason || 'Не указана')
                    : (data.status && data.status !== 'open' ? data.status : 'Отправка сообщений недоступна.');
            }
        } catch { /* retry on reconnect */ }
        finally { syncing = false; }
    };

    const openConversation = async () => {
        const selected = descriptor;
        const notification = active;
        const response = await jsonGet('/account/notifications/popover/' + selected.kind + '/' + selected.id);
        if (active !== notification || descriptor !== selected || root.hidden) return;
        if (response.status === 403 || response.status === 404) { accessLost(); return; }
        if (!response.ok) throw new Error('Не удалось открыть переписку.');
        const result = await response.json();
        if (active !== notification || descriptor !== selected || result.kind !== selected.kind) return;
        contract = result;
        content.innerHTML = '<div class="app-notification-popover__history">'
            + '<button type="button" data-popover-older hidden>Показать предыдущие</button>'
            + '<div data-popover-log class="app-notification-popover__log" role="log" aria-live="polite"></div></div>'
            + '<p data-popover-status class="app-notification-popover__status" hidden></p>'
            + '<form data-popover-form class="app-notification-popover__form"><label for="mskba-popover-input">Ответить</label>'
            + '<textarea id="mskba-popover-input" data-popover-input rows="3" placeholder="Напишите сообщение…" maxlength="'
            + (Number(result.limit) === 500 ? 500 : 2000) + '"></textarea>'
            + '<button type="submit" data-popover-send>Отправить</button></form>'
            + '<p data-popover-error class="app-notification-popover__error" role="status"></p>';
        const older = content.querySelector('[data-popover-older]');
        older.addEventListener('click', async () => {
            older.disabled = true;
            try { await history(true); } catch (error) { showError(error.message); }
            finally { older.disabled = false; }
        });
        content.querySelector('[data-popover-form]').hidden = !Boolean(result.can_send);
        const input = content.querySelector('[data-popover-input]');
        input.value = session.get(draftKey()) || '';
        input.addEventListener('input', keepDraft);
        content.querySelector('[data-popover-form]').addEventListener('submit', async (event) => {
            event.preventDefault();
            if (sending || !contract) return;
            const body = input.value.trim();
            if (!body || body.length > Number(contract.limit)) {
                showError('Введите сообщение до ' + contract.limit + ' символов.');
                return;
            }
            sending = true;
            const selectedContract = contract;
            const button = content.querySelector('[data-popover-send]');
            button.disabled = true;
            showError('');
            try {
                const reply = await fetch(contract.send_url, {
                    method: 'POST', credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest',
                        'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
                    body: JSON.stringify({ body }),
                });
                if (selectedContract !== contract || root.hidden) return;
                if (reply.status === 403 || reply.status === 404) { accessLost(); return; }
                if (!reply.ok) throw new Error(reply.status === 429
                    ? 'Слишком много сообщений. Попробуйте позже.'
                    : 'Не удалось отправить сообщение.');
                input.value = '';
                session.del(draftKey());
                await history();
            } catch (error) { showError(error.message); }
            finally { sending = false; if (button.isConnected) button.disabled = false; }
        });
        await history();
        await state();
        // An older request must never subscribe after the user switched dialogs.
        if (active !== notification || descriptor !== selected || contract !== result || root.hidden) return;
        unsubscribe = subscribePrivate(result.channel, '.conversation.changed', () => {
            void state(); void history().catch(() => {});
        });
        polling = setInterval(() => {
            if (!document.hidden) { void state(); void history().catch(() => {}); }
        }, 30000);
    };

    const showDetails = (notice) => {
        content.replaceChildren();
        const body = document.createElement('p');
        body.textContent = notice.body || 'Подробности доступны на странице уведомления.';
        content.append(body);
        if (Array.isArray(notice.actions) && notice.actions.length) {
            const available = notice.context?.source === 'team.invitation.created'
                ? notice.actions.filter(action => {
                    const url = safeHref(action.url);
                    return url && /^\/team-invitations\/[1-9]\d*$/.test(new URL(url).pathname)
                        && action.method === 'PATCH' && ['accept', 'decline'].includes(action.key);
                }) : [];
            if (!available.length) {
                const hint = document.createElement('p');
                hint.className = 'app-notification-popover__hint';
                hint.textContent = 'Для управления этим уведомлением откройте его страницу.';
                content.append(hint);
                return;
            }
            const actions = document.createElement('div');
            actions.className = 'app-notification-popover__actions';
            const status = document.createElement('p');
            status.className = 'app-notification-popover__hint';
            status.setAttribute('role', 'status');
            for (const action of available) {
                const button = document.createElement('button');
                button.type = 'button';
                button.textContent = action.label;
                button.addEventListener('click', async () => {
                    if (button.dataset.confirm !== 'yes') {
                        button.dataset.confirm = 'yes';
                        button.textContent = 'Подтвердить: ' + action.label;
                        return;
                    }
                    actions.querySelectorAll('button').forEach(item => { item.disabled = true; });
                    try {
                        const response = await fetch(safeHref(action.url), {
                            method: 'PATCH', credentials: 'same-origin',
                            headers: { Accept: 'application/json', 'Content-Type': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf },
                            body: JSON.stringify({ decision: action.key }),
                        });
                        if (!response.ok) {
                            const error = await response.json().catch(() => ({}));
                            throw new Error(error.message || 'Не удалось обработать приглашение.');
                        }
                        if (active !== notice) return;
                        const result = await response.json();
                        status.textContent = result.message || 'Действие выполнено.';
                        actions.remove();
                        read.disabled = true;
                        read.textContent = 'Прочитано';
                        document.dispatchEvent(new CustomEvent('notification:count-changed', { detail: {} }));
                    } catch (error) {
                        status.textContent = error.message;
                        actions.querySelectorAll('button').forEach(item => { item.disabled = false; });
                    }
                });
                actions.append(button);
            }
            content.append(actions, status);
        }
    };

    const open = async (notice) => {
        if (!notice?.id) return;
        keepDraft();
        stop();
        syncing = false;
        sending = false;
        restoreFocus = document.activeElement;
        active = notice;
        descriptor = conversationFromHref(notice.href, window.location.origin);
        contract = null;
        root.hidden = false;
        backdrop.hidden = false;
        document.body.classList.add('app-notification-popover-open');
        session.set(key, String(notice.id));
        heading.textContent = notice.title || 'Уведомление MSKBA';
        original.href = safeHref(notice.href) || '/account/notifications';
        read.disabled = false;
        read.textContent = 'Отметить прочитанным';
        const saved = session.get(placeKey);
        if (saved && window.matchMedia('(min-width: 768px)').matches) {
            try {
                const point = JSON.parse(saved);
                if (Number(point.width) >= 290 && Number(point.width) < window.innerWidth - 16)
                    root.style.width = Number(point.width) + 'px';
                if (Number(point.height) >= 260 && Number(point.height) < window.innerHeight - 16)
                    root.style.height = Number(point.height) + 'px';
                const x = Math.max(8, Math.min(window.innerWidth - root.offsetWidth - 8, Number(point.x) || 0));
                const y = Math.max(8, Math.min(window.innerHeight - 65, Number(point.y) || 0));
                root.style.left = x + 'px'; root.style.top = y + 'px';
                root.style.right = 'auto'; root.style.bottom = 'auto';
            } catch { /* invalid position */ }
        }
        if (!descriptor) showDetails(notice);
        else {
            content.textContent = 'Загружаем переписку…';
            try { await openConversation(); }
            catch (error) { if (active?.id === notice.id) showError(error.message); }
        }
        root.querySelector('[data-popover-close]').focus();
    };

    read.addEventListener('click', async () => {
        if (!active) return;
        const selected = active;
        read.disabled = true;
        try {
            const response = await fetch(selected.read_url, { method: 'PATCH', credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf } });
            if (!response.ok) throw new Error('Не удалось отметить прочитанным.');
            const data = await response.json();
            if (active !== selected) return;
            document.dispatchEvent(new CustomEvent('notification:count-changed',
                { detail: { unread_count: data.unread_count } }));
            read.textContent = 'Прочитано';
        } catch (error) {
            if (active === selected) { read.disabled = false; showError(error.message); }
        }
    });

    const handle = root.querySelector('[data-popover-drag]');
    handle.tabIndex = 0;
    const savePosition = () => session.set(placeKey, JSON.stringify({
        x: root.getBoundingClientRect().left, y: root.getBoundingClientRect().top,
        width: root.offsetWidth, height: root.offsetHeight,
    }));
    handle.addEventListener('keydown', (event) => {
        if (!window.matchMedia('(min-width: 768px)').matches) return;
        const arrows = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] };
        const direction = arrows[event.key];
        if (!direction) return;
        event.preventDefault();
        const box = root.getBoundingClientRect();
        const step = event.shiftKey ? 20 : 8;
        const x = Math.max(8, Math.min(window.innerWidth - root.offsetWidth - 8, box.left + direction[0] * step));
        const y = Math.max(8, Math.min(window.innerHeight - 65, box.top + direction[1] * step));
        root.style.left = x + 'px'; root.style.top = y + 'px';
        root.style.right = 'auto'; root.style.bottom = 'auto';
        savePosition();
    });
    handle.addEventListener('pointerdown', (event) => {
        if (event.button !== 0 || !window.matchMedia('(min-width: 768px)').matches
            || event.target.closest('button,a,input,textarea')) return;
        const rect = root.getBoundingClientRect();
        const dx = event.clientX - rect.left, dy = event.clientY - rect.top;
        handle.setPointerCapture?.(event.pointerId);
        const move = (pointer) => {
            if (pointer.pointerId !== event.pointerId) return;
            const x = Math.max(8, Math.min(window.innerWidth - root.offsetWidth - 8, pointer.clientX - dx));
            const y = Math.max(8, Math.min(window.innerHeight - 50, pointer.clientY - dy));
            root.style.left = x + 'px'; root.style.top = y + 'px';
            root.style.right = 'auto'; root.style.bottom = 'auto';
        };
        const stopMove = (pointer) => {
            if (pointer.pointerId !== event.pointerId) return;
            handle.removeEventListener('pointermove', move);
            handle.removeEventListener('pointerup', stopMove);
            handle.removeEventListener('pointercancel', stopMove);
            savePosition();
        };
        handle.addEventListener('pointermove', move);
        handle.addEventListener('pointerup', stopMove);
        handle.addEventListener('pointercancel', stopMove);
    });

    if (typeof ResizeObserver !== 'undefined') {
        const observer = new ResizeObserver(() => {
            if (!root.hidden && window.matchMedia('(min-width: 768px)').matches) savePosition();
        });
        observer.observe(root);
    }
    window.addEventListener('pagehide', stop);
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !root.hidden) { event.preventDefault(); close(); }
    });
    window.addEventListener('focus', () => {
        if (!root.hidden) { void state(); void history().catch(() => {}); }
    });
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden && !root.hidden) { void state(); void history().catch(() => {}); }
    });
    const restore = (items) => {
        if (!root.hidden) return;
        const id = session.get(key);
        const item = items.find(one => String(one.id) === id);
        if (item) void open(item);
    };

    return { open, close, restore, isOpen: () => !root.hidden };
};
