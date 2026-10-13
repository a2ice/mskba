import { subscribePrivate } from '../../../js/realtime.js';

const root = document.querySelector('[data-chat-root]');
if (root) {
    const viewport = root.querySelector('[data-chat-scroll]');
    const log = root.querySelector('[data-chat-history]');
    const loader = root.querySelector('[data-chat-load]');
    const form = root.querySelector('[data-chat-compose]');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    let before = log?.dataset.nextBefore || null;
    let loading = false;
    let latestPending = false;
    let refreshQueued = false;
    let serial = 0;
    // Broadcast delivery is independent from visibility. Acknowledgement is NOT:
    // it requires an actually focused, visible chat scrolled to the latest message.
    let unreadPossible = Boolean(root.dataset.chatReadUrl) && Number(root.dataset.chatUnreadInitial || 0) > 0;
    let readRevision = 0;
    let readPending = false;

    const bottom = () => { if (viewport) viewport.scrollTop = viewport.scrollHeight; };
    const nearBottom = () => viewport && viewport.scrollHeight - viewport.scrollTop - viewport.clientHeight < 100;
    const canAcknowledge = () => !document.hidden && document.hasFocus() && nearBottom();
    const acknowledge = async () => {
        if (!unreadPossible || readPending || !canAcknowledge()) return;
        readPending = true;
        const revision = readRevision;
        try {
            const response = await fetch(root.dataset.chatReadUrl, {
                method: 'PATCH', credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf },
            });
            if (response.status === 403 || response.status === 404) {
                window.location.reload(); // Never acknowledge a forbidden conversation.
                return;
            }
            if (!response.ok) throw new Error('Не удалось отметить переписку прочитанной.');
            const result = await response.json();
            // A new message may arrive during PATCH. Do not lose that read intent.
            if (revision === readRevision) unreadPossible = false;
            document.dispatchEvent(new CustomEvent('notification:count-changed', {
                detail: { unread_count: result.unread_count },
            }));
        } catch (error) {
            showError(error.message || 'Не удалось отметить переписку прочитанной.');
        } finally {
            readPending = false;
            if (unreadPossible && revision !== readRevision && canAcknowledge()) void acknowledge();
        }
    };
    const fetchPage = async (cursor = null) => {
        const url = new URL(root.dataset.chatHistoryUrl, window.location.origin);
        if (cursor) url.searchParams.set('before', cursor);
        const response = await fetch(url, {
            credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (response.status === 403 || response.status === 404) {
            window.location.reload(); // Server revoked access: never retain private history.
            throw new Error('Доступ к переписке изменился.');
        }
        if (!response.ok) throw new Error('Не удалось загрузить сообщения.');
        return response.json();
    };
    const parse = html => {
        const template = document.createElement('template');
        template.innerHTML = html;
        return [...template.content.children];
    };
    const showError = message => {
        const target = root.querySelector('[data-chat-error]');
        if (target) { target.textContent = message; target.hidden = !message; }
        else if (loader) { loader.hidden = false; loader.querySelector('span:last-child').textContent = message; }
    };
    const older = async () => {
        if (!before || loading || !viewport || !log) return;
        loading = true;
        loader.hidden = false;
        const height = viewport.scrollHeight;
        const top = viewport.scrollTop;
        try {
            const page = await fetchPage(before);
            const existing = new Set([...log.children].map(node => node.dataset.chatEntry));
            const entries = parse(page.html).filter(node => !existing.has(node.dataset.chatEntry));
            log.prepend(...entries);
            before = page.next_before;
            log.dataset.nextBefore = before || '';
            viewport.scrollTop = top + (viewport.scrollHeight - height);
            loader.hidden = true;
        } catch (error) {
            showError(error.message);
        } finally { loading = false; if (loader && before) loader.hidden = true; }
    };
    const refresh = async () => {
        // Hidden windows must still receive Reverb messages in their history.
        // Only marking them read is guarded by focus/visibility.
        if (latestPending) { refreshQueued = true; return; }
        latestPending = true;
        const token = ++serial;
        try {
            const shouldStick = nearBottom();
            const page = await fetchPage();
            if (token !== serial) return;
            const existing = new Set([...log.children].map(node => node.dataset.chatEntry));
            const incoming = parse(page.html).filter(node => !existing.has(node.dataset.chatEntry));
            log.append(...incoming);
            if (incoming.length > 0) { unreadPossible = true; readRevision += 1; }
            if (!before) {
                before = page.next_before;
                log.dataset.nextBefore = before || '';
                loader.hidden = true;
            }
            if (shouldStick) bottom();
            // Content is now rendered; only the foreground viewer at the end
            // has actually seen the latest messages.
            if (canAcknowledge()) void acknowledge();
        } catch (error) {
            showError(error.message);
        } finally {
            latestPending = false;
            if (refreshQueued) { refreshQueued = false; void refresh(); }
        }
    };

    if (viewport && log) {
        // Scroll inside the chat card, never the entire page.
        requestAnimationFrame(() => { bottom(); void acknowledge(); });
        viewport.addEventListener('scroll', () => {
            if (viewport.scrollTop < 56) void older();
            if (canAcknowledge()) void acknowledge();
        });
    }

    if (form) {
        const textarea = form.querySelector('textarea[name="body"]');
        // The Actions flyout also contains submit buttons for *other* forms.
        // Only the dedicated composer submitter can be disabled while sending.
        const send = form.querySelector('[data-chat-send]');
        textarea?.addEventListener('keydown', event => {
            if (event.key === 'Enter' && (event.metaKey || event.ctrlKey) && !event.isComposing) {
                event.preventDefault();
                form.requestSubmit();
            }
        });
        form.addEventListener('submit', async event => {
            event.preventDefault();
            if (!textarea.value.trim() || send.disabled) return;
            const message = textarea.value.trim();
            send.disabled = true;
            showError('');
            try {
                const response = await fetch(form.action || form.dataset.sendUrl, {
                    method: 'POST', credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ body: message }),
                });
                if (!response.ok) {
                    const payload = await response.json().catch(() => ({}));
                    if (response.status === 403 || response.status === 404) window.location.reload();
                    throw new Error(Object.values(payload.errors || {}).flat()[0] || payload.message || 'Сообщение не отправлено.');
                }
                textarea.value = '';
                await refresh();
                bottom();
            } catch (error) { showError(error.message); }
            finally { send.disabled = false; }
        });
    }

    const actions = root.querySelector('[data-chat-actions]');
    document.addEventListener('pointerdown', event => {
        if (actions?.open && !actions.contains(event.target)) actions.open = false;
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && actions?.open) actions.open = false;
    });
    actions?.querySelector('[data-chat-reviewers-toggle]')?.addEventListener('click', () => {
        const section = root.querySelector('[data-chat-reviewers]');
        if (!section) return;
        section.hidden = !section.hidden;
        actions.open = false;
        if (!section.hidden) section.scrollIntoView({ block: 'nearest' });
    });
    const dialog = root.querySelector('[data-chat-review-dialog]');
    root.querySelectorAll('[data-review-dialog-open]').forEach(button => {
        button.addEventListener('click', () => {
            const kind = button.dataset.reviewDialogOpen;
            dialog.querySelector('[data-review-action]').value = kind;
            dialog.querySelector('[data-review-title]').textContent = kind === 'reject' ? 'Отклонить заявку' : 'Заблокировать заявку';
            dialog.showModal();
            actions.open = false;
            dialog.querySelector('textarea')?.focus();
        });
    });
    dialog?.querySelector('[data-review-dialog-close]')?.addEventListener('click', () => dialog.close());
    dialog?.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });

    const channel = root.dataset.chatChannel;
    if (channel) subscribePrivate(channel, '.conversation.changed', payload => {
        if (payload?.access_changed) { window.location.reload(); return; }
        unreadPossible = true;
        readRevision += 1;
        void refresh();
    });
    // Returning to a background window also reconciles notifications that
    // arrived while inactive. read acknowledgement waits for this fetch.
    window.addEventListener('focus', () => void refresh());
    window.addEventListener('mskba:realtime-state', event => {
        if (event.detail?.state === 'connected') void refresh();
    });
    document.addEventListener('visibilitychange', () => { if (!document.hidden) void refresh(); });
}
