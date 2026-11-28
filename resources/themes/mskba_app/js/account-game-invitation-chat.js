import { subscribePrivate } from '../../../js/realtime.js';

const root = document.querySelector('[data-game-chat-root]');
if (root) {
    const content = root.querySelector('[data-game-chat-content]');
    const error = root.querySelector('[data-game-chat-error]');
    const compose = root.querySelector('[data-game-chat-compose]');
    const rail = document.querySelector('[data-conversation-rail]');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    let pending = false, queued = false, sending = false, stopped = false;
    let lastHtml = null, lastRail = null, readPending = false, unread = true;
    const showError = message => { error.textContent = message || ''; error.hidden = !message; };
    const dialog = () => content.querySelector('[data-game-teammates-dialog]');
    const scroll = () => content.querySelector('[data-game-chat-scroll]');
    const nearBottom = node => node && node.scrollHeight - node.clientHeight - node.scrollTop < 100;
    const latestPage = () => Number(new URL(location.href).searchParams.get('page') || 1) === 1;
    const allowedToRead = () => latestPage() && !document.hidden && document.hasFocus() && nearBottom(scroll());
    function revoke() {
        stopped = true;
        dialog()?.close();
        content.replaceChildren();
        compose?.remove();
        showError('Доступ к переписке изменился.');
        location.reload();
    }
    function syncSelection(modal) {
        if (!modal) return;
        const all = modal.querySelector('[data-game-teammates-all]');
        const checks = [...modal.querySelectorAll('input[name="recipients[]"]:not(:disabled)')];
        const count = checks.filter(check => check.checked).length;
        all.checked = count > 0 && count === checks.length;
        all.indeterminate = count > 0 && count < checks.length;
        all.disabled = !checks.length;
        modal.querySelector('[data-game-teammates-submit]').disabled = !count || sending;
    }
    function replaceContent(html) {
        if (html === lastHtml) return;
        const viewport = scroll(), sticky = latestPage() && nearBottom(viewport), top = viewport?.scrollTop || 0;
        const previous = dialog(), open = previous?.open;
        const previousForm = previous?.querySelector('form');
        const draft = open ? {
            action: previousForm.action,
            comment: previousForm.elements.comment.value,
            choices: new Map([...previousForm.querySelectorAll('input[name="recipients[]"]')].map(input => [input.value, input.checked])),
        } : null;
        if (open) previous.close();
        content.innerHTML = html;
        lastHtml = html;
        const next = dialog(), nextForm = next?.querySelector('form');
        if (draft && nextForm?.action === draft.action) {
            nextForm.elements.comment.value = draft.comment;
            nextForm.querySelectorAll('input[name="recipients[]"]:not(:disabled)').forEach(input => {
                input.checked = draft.choices.get(input.value) ?? false;
            });
            syncSelection(next);
            next.showModal();
        } else {
            syncSelection(next);
        }
        const updated = scroll();
        if (updated) updated.scrollTop = sticky ? updated.scrollHeight : top;
    }
    function replaceRail(html) {
        const list = rail?.querySelector('[data-rail-list]');
        if (!list || typeof html !== 'string' || html === lastRail) return;
        const expanded = new Map([...list.querySelectorAll('[data-conversation-group]')].map(group => [group.dataset.conversationGroup, group.open]));
        list.innerHTML = html;
        lastRail = html;
        list.querySelectorAll('[data-conversation-group]').forEach(group => {
            if (group.dataset.groupActive === 'true') group.open = true;
            else if (expanded.has(group.dataset.conversationGroup)) group.open = expanded.get(group.dataset.conversationGroup);
        });
    }
    async function acknowledge() {
        if (stopped || readPending || pending || sending || !unread || !allowedToRead()) return;
        readPending = true;
        unread = false;
        try {
            const response = await fetch(root.dataset.chatReadUrl, { method: 'PATCH', credentials: 'same-origin', headers: {...headers, 'X-CSRF-TOKEN': csrf} });
            if ([401,403,404].includes(response.status)) return revoke();
            if (!response.ok) throw Error('Не удалось отметить сообщения прочитанными.');
            const data = await response.json();
            document.dispatchEvent(new CustomEvent('notification:count-changed', {detail:{unread_count:data.unread_count}}));
        } catch { unread = true; }
        finally { readPending = false; }
    }
    async function refresh() {
        if (stopped) return;
        if (pending || sending) { queued = true; return; }
        pending = true;
        try {
            const url = new URL(root.dataset.chatUrl, location.origin);
            const page = new URL(location.href).searchParams.get('page');
            if (page) url.searchParams.set('page',page);
            url.searchParams.set('fragment','1');
            const response = await fetch(url,{credentials:'same-origin',cache:'no-store',headers});
            if ([401,403,404].includes(response.status) || response.redirected) return revoke();
            if (!response.ok) throw Error('Не удалось обновить переписку.');
            const data = await response.json();
            if (sending) { queued = true; return; }
            if (typeof data.html !== 'string') throw Error('Не удалось обновить переписку.');
            replaceContent(data.html);
            replaceRail(data.rail_html);
        } catch (e) { showError(e.message); }
        finally {
            pending = false;
            if (queued && !sending && !stopped) { queued = false; void refresh(); }
            else void acknowledge();
        }
    }
    function changed() { unread = true; void refresh(); }
    root.addEventListener('click', event => {
        if (event.target.closest('[data-game-teammates-open]')) {
            const modal = dialog(); syncSelection(modal); modal?.showModal();
        }
        if (event.target.closest('[data-game-teammates-close]') && !sending) dialog()?.close();
    });
    root.addEventListener('change', event => {
        const modal = dialog();
        if (!modal?.contains(event.target)) return;
        if (event.target.matches('[data-game-teammates-all]')) {
            modal.querySelectorAll('input[name="recipients[]"]:not(:disabled)').forEach(input => input.checked = event.target.checked);
        }
        syncSelection(modal);
    });
    root.addEventListener('cancel', event => { if (sending) event.preventDefault(); }, true);
    root.addEventListener('scroll', () => void acknowledge(), true);
    root.addEventListener('submit', async event => {
        const form = event.target;
        if (!form.matches('[data-game-chat-compose],[data-game-chat-respond],[data-game-teammates-form]')) return;
        event.preventDefault();
        if (sending || stopped) return;
        const body = new FormData(form);
        if (event.submitter?.name) body.set(event.submitter.name,event.submitter.value);
        if (form === compose && !String(body.get('body')||'').trim()) return;
        const buttons = [...form.querySelectorAll('button[type="submit"]')];
        sending = true; buttons.forEach(button => button.disabled = true); showError('');
        try {
            const response = await fetch(form.action,{method:'POST',credentials:'same-origin',headers:{...headers,'X-CSRF-TOKEN':csrf},body});
            if ([401,403,404].includes(response.status) || response.redirected) return revoke();
            const data = await response.json();
            if (!response.ok) throw Error(Object.values(data.errors||{}).flat()[0]||data.message||'Не удалось выполнить действие.');
            if (form === compose) {
                const input = compose.elements.body;
                if (input.value === body.get('body')) input.value = '';
            }
            if (form.matches('[data-game-teammates-form]')) dialog()?.close();
        } catch(e) { showError(e.message); }
        finally {
            sending = false;
            buttons.forEach(button => button.disabled = false);
            syncSelection(dialog());
            queued = false;
            changed();
        }
    });
    const unsubscribe = subscribePrivate(root.dataset.chatChannel,'.conversation.changed',changed);
    const onFocus = () => changed();
    const onVisibility = () => { if (!document.hidden) changed(); };
    const onRealtime = event => { if (event.detail?.state === 'connected') changed(); };
    window.addEventListener('focus',onFocus);
    document.addEventListener('visibilitychange',onVisibility);
    window.addEventListener('mskba:realtime-state',onRealtime);
    const timer = window.setInterval(() => { if (!document.hidden) changed(); },15000);
    window.addEventListener('pagehide',() => {
        stopped = true; unsubscribe(); window.clearInterval(timer);
        window.removeEventListener('focus',onFocus);
        document.removeEventListener('visibilitychange',onVisibility);
        window.removeEventListener('mskba:realtime-state',onRealtime);
    },{once:true});
    syncSelection(dialog());
    if (latestPage() && scroll()) scroll().scrollTop = scroll().scrollHeight;
    void refresh();
}
