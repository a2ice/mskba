import { subscribePrivate } from '../../../js/realtime.js';

const root = document.querySelector('[data-chat-root]');
if (root) {
    const viewport = root.querySelector('[data-chat-scroll]');
    const log = root.querySelector('[data-chat-history]');
    const loader = root.querySelector('[data-chat-load]');
    const form = root.querySelector('[data-chat-compose]');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const directStateUrl = root.dataset.chatDirectStateUrl || null;
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
    // Derive daily boundaries from the messages present in the DOM. Recompute
    // after each keyset prepend and realtime append so page boundaries never
    // create duplicate or missing day separators.
    const renderDays = () => {
        if (!log) return;
        log.querySelectorAll('[data-chat-day]').forEach(separator => separator.remove());
        let previous = null;
        const formatter = new Intl.DateTimeFormat('ru-RU', {
            day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC',
        });
        for (const entry of log.querySelectorAll('[data-chat-entry][data-chat-date]')) {
            const day = entry.dataset.chatDate;
            if (!day || day === previous) continue;
            const separator = document.createElement('div');
            separator.className = 'app-messages__day';
            separator.dataset.chatDay = day;
            separator.textContent = formatter.format(new Date(`${day}T12:00:00Z`));
            separator.setAttribute('role', 'separator');
            separator.setAttribute('aria-label', separator.textContent);
            entry.before(separator);
            previous = day;
        }
    };
    renderDays();
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
            renderDays();
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
            if (incoming.length > 0) log.querySelector('.app-messages__thread-empty')?.remove();
            log.append(...incoming);
            if (incoming.length > 0) renderDays();
            if (incoming.some(node => node.dataset.chatKind !== 'event')) {
                unreadPossible = true;
                readRevision += 1;
            }
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

    const textarea = form?.querySelector('textarea[name="body"]');
    const charCount = form?.querySelector('[data-direct-char-count]');
    if (directStateUrl && textarea && charCount) {
        const updateCount = () => { charCount.textContent = `${textarea.value.length} / 500`; };
        textarea.addEventListener('input', updateCount);
        updateCount();
    }
    // The Actions flyout also contains submit buttons for *other* forms.
    const send = form?.querySelector('[data-chat-send]');
    const directStatus = root.querySelector('[data-chat-direct-status]');
    const blockAction = root.querySelector('[data-direct-block-action]');
    const unblockAction = root.querySelector('[data-direct-unblock-action]');
    let canSendDirect = textarea ? !textarea.disabled : false;
    let sendingDirect = false;
    let stateSerial = 0;
    const refreshDirectState = async () => {
        if (!directStateUrl) return;
        const current = ++stateSerial;
        try {
            const response = await fetch(directStateUrl, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (response.status === 403 || response.status === 404) {
                window.location.reload();
                return;
            }
            if (!response.ok) throw new Error('Не удалось обновить доступ к переписке.');
            const data = await response.json();
            if (current !== stateSerial) return;
            canSendDirect = Boolean(data.can_send);
            if (textarea) {
                textarea.disabled = !canSendDirect;
                textarea.hidden = !canSendDirect;
                textarea.placeholder = 'Введите сообщение…';
            }
            if (charCount) charCount.hidden = !canSendDirect;
            if (send) send.disabled = !canSendDirect || sendingDirect;
            if (blockAction) blockAction.hidden = Boolean(data.blocked_by_me);
            if (unblockAction) unblockAction.hidden = !data.blocked_by_me;
            if (directStatus) {
                directStatus.textContent = data.status || '';
                directStatus.hidden = canSendDirect;
            }
            if (canSendDirect) showError('');
        } catch (error) {
            showError(error.message || 'Не удалось обновить доступ.');
        }
    };
    if (directStateUrl) {
        root.querySelectorAll('[data-direct-block-form]').forEach(blockForm => {
            blockForm.addEventListener('submit', async event => {
                event.preventDefault();
                const button = root.querySelector(`[form="${blockForm.id}"]`);
                if (button) button.disabled = true;
                try {
                    const response = await fetch(blockForm.action, {
                        method: blockForm.querySelector('[name="_method"]')?.value || 'POST',
                        credentials: 'same-origin',
                        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf },
                    });
                    if (!response.ok) throw new Error('Не удалось изменить блокировку пользователя.');
                    root.querySelector('[data-chat-actions]')?.removeAttribute('open');
                    await Promise.all([refreshDirectState(), refresh(), refreshRail()]);
                } catch (error) { showError(error.message); }
                finally { if (button) button.disabled = false; }
            });
        });
    }

    if (form) {
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
            sendingDirect = true;
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
                    if (response.status === 403 && directStateUrl) void refreshDirectState();
                    else if (response.status === 403 || response.status === 404) window.location.reload();
                    throw new Error(Object.values(payload.errors || {}).flat()[0] || payload.message || 'Сообщение не отправлено.');
                }
                textarea.value = '';
                textarea.dispatchEvent(new Event('input'));
                await refresh();
                // The author does not receive an unread notification for their
                // own message. Always sync the server-side inbox preview after
                // a successful POST, independently of Reverb or read events.
                void refreshRail();
                bottom();
            } catch (error) { showError(error.message); }
            finally { sendingDirect = false; send.disabled = directStateUrl ? !canSendDirect : false; }
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

    // Refresh the inbox rail with its existing authorization model rather than
    // deriving private previews from raw realtime payloads.
    const rail = document.querySelector('[data-conversation-rail]');
    const railList = rail?.querySelector('[data-rail-list]');
    let railBusy = false;
    let railQueued = false;
    const refreshRail = async () => {
        if (!rail || !railList || railBusy || rail.hidden || getComputedStyle(rail).display === 'none') {
            if (railBusy) railQueued = true;
            return;
        }
        railBusy = true;
        try {
            const url = new URL(rail.dataset.railUrl, window.location.origin);
            url.searchParams.set('active', rail.dataset.railActive);
            const response = await fetch(url, { credentials:'same-origin',
                headers:{ Accept:'application/json','X-Requested-With':'XMLHttpRequest' } });
            if (!response.ok) return;
            const data = await response.json();
            // A realtime refresh must not reopen groups the viewer collapsed.
            const expanded = new Map([...railList.querySelectorAll('[data-conversation-group]')]
                .map(group => [group.dataset.conversationGroup, group.open]));
            railList.innerHTML = data.html;
            railList.querySelectorAll('[data-conversation-group]').forEach(group => {
                if (group.dataset.groupActive === 'true') group.open = true;
                else if (expanded.has(group.dataset.conversationGroup)) {
                    group.open = expanded.get(group.dataset.conversationGroup);
                }
            });
        } catch (_error) { /* Retain the last authorized list until connectivity resumes. */ }
        finally {
            railBusy = false;
            if (railQueued) { railQueued = false; void refreshRail(); }
        }
    };
    document.addEventListener('notification:count-changed', () => void refreshRail());
    // The current thread channel only covers this one conversation. Other
    // conversations still generate private user notifications, so refresh the
    // left inbox rail for any notified conversation without exposing its body
    // in the websocket payload. The read-only system stream also refreshes.
    const notificationHeader = document.querySelector('[data-app-header][data-notification-user-id]');
    if (notificationHeader) subscribePrivate(`users.${notificationHeader.dataset.notificationUserId}`,
        '.notification.created', () => {
            if (root.hasAttribute('data-chat-system-notifications')) void refresh();
            void refreshRail();
        });
    const channel = root.dataset.chatChannel;
    if (channel) subscribePrivate(channel, '.conversation.changed', payload => {
        if (payload?.access_changed) {
            if (!directStateUrl) { window.location.reload(); return; }
            void refreshDirectState();
            void refresh();
            void refreshRail();
            return;
        }
        unreadPossible = true;
        readRevision += 1;
        // A new outgoing message can grant the other participant the right
        // to reply even if the sender's privacy is set to NOBODY.
        void refreshDirectState();
        void refresh();
        void refreshRail();
    });
    // Returning to a background window also reconciles notifications that
    // arrived while inactive. read acknowledgement waits for this fetch.
    window.addEventListener('focus', () => { void refreshDirectState(); void refresh(); void refreshRail(); });
    window.addEventListener('mskba:realtime-state', event => {
        if (event.detail?.state === 'connected') { void refreshDirectState(); void refresh(); void refreshRail(); }
    });
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) { void refreshDirectState(); void refresh(); void refreshRail(); }
    });
}


// A native modal keeps the requested close reason in a real server-validated form.
document.querySelectorAll('[data-support-close-dialog]').forEach(dialog => {
    const trigger = document.querySelector('[data-support-close-trigger]');
    const cancel = dialog.querySelector('[data-support-close-cancel]');
    const reason = dialog.querySelector('[data-support-close-reason]');
    const wrap = dialog.querySelector('[data-support-close-description-wrap]');
    const description = dialog.querySelector('[data-support-close-description]');
    const syncReason = () => {
        const other = reason?.value === 'other';
        if (wrap) wrap.hidden = !other;
        if (description) {
            description.required = other;
            if (!other) description.value = '';
        }
    };
    trigger?.addEventListener('click', () => {
        syncReason();
        dialog.showModal();
    });
    cancel?.addEventListener('click', () => dialog.close());
    reason?.addEventListener('change', syncReason);
    dialog.addEventListener('click', event => {
        if (event.target === dialog) dialog.close();
    });
    syncReason();
});
