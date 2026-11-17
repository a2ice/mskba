import { subscribePrivate } from '../../../js/realtime.js';

const root = document.querySelector('[data-team-chat-root]');
if (root) {
    const history = root.querySelector('[data-team-chat-history]');
    const scroll = root.querySelector('[data-team-chat-scroll]');
    const form = root.querySelector('[data-team-chat-compose]');
    const textarea = form?.querySelector('textarea[name="body"]');
    const send = root.querySelector('[data-team-chat-send]');
    const closed = root.querySelector('[data-team-chat-closed]');
    const actions = root.querySelector('[data-team-action-panel]');
    const status = root.querySelector('[data-team-status-label]');
    const error = root.querySelector('[data-team-chat-error]');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    let busy = false;
    let currentHtml = history?.innerHTML || '';
    const showError = message => { if (error) { error.textContent = message; error.hidden = !message; } };
    const acknowledge = async () => {
        if (document.hidden || !document.hasFocus()) return;
        try {
            const res = await fetch(root.dataset.teamReadUrl, { method:'PATCH',
                credentials:'same-origin', headers:{Accept:'application/json','X-CSRF-TOKEN':csrf} });
            if (res.ok) {
                const data = await res.json();
                document.dispatchEvent(new CustomEvent('notification:count-changed', {detail:{unread_count:data.unread_count}}));
            }
        } catch { /* Read marker is retried on the next visible refresh. */ }
    };
    const refresh = async () => {
        try {
            const response = await fetch(root.dataset.teamStateUrl, {headers:{Accept:'application/json'},credentials:'same-origin'});
            if (response.status === 403 || response.status === 404) { window.location.reload(); return; }
            if (!response.ok) throw new Error('Не удалось обновить переписку.');
            const data = await response.json();
            if (status) status.textContent = data.status;
            if (actions && actions.innerHTML !== data.actions_html) {
                actions.innerHTML = data.actions_html;
            }
            if (textarea) { textarea.disabled = !data.can_write; textarea.hidden = !data.can_write; }
            if (send) { send.hidden = !data.can_write; send.disabled = !data.can_write; }
            if (closed) closed.hidden = data.can_write;
            if (history && data.html !== currentHtml) {
                const nearEnd = scroll.scrollHeight-scroll.scrollTop-scroll.clientHeight<100;
                history.innerHTML = data.html;
                currentHtml = data.html;
                if (nearEnd) scroll.scrollTop = scroll.scrollHeight;
                void acknowledge();
            }
        } catch (e) { showError(e.message); }
    };
    const start = () => { if (scroll) scroll.scrollTop = scroll.scrollHeight; };
    start();
    root.addEventListener('click', event => {
        const trigger = event.target.closest('[data-team-review-trigger]');
        if (trigger) {
            const dialog = root.querySelector('[data-team-review-dialog]');
            if (!dialog) return;
            const action = trigger.dataset.teamReviewTrigger;
            dialog.querySelector('[data-team-review-action]').value=action;
            const title = {accept:'Принять заявку',reject:'Отклонить заявку',block:'Заблокировать заявку',unblock:'Разблокировать заявку'}[action];
            dialog.querySelector('[data-team-review-title]').textContent=title;
            const reason = dialog.querySelector('[data-team-review-reason]');
            reason.required = action === 'reject' || action === 'block';
            dialog.querySelector('[data-team-review-reason-wrap]').hidden = !reason.required;
            reason.value = '';
            root.querySelector('[data-chat-actions]')?.removeAttribute('open');
            dialog.showModal();
        }
        if (event.target.closest('[data-team-review-cancel]')) {
            root.querySelector('[data-team-review-dialog]')?.close();
        }
    });
    root.addEventListener('click', event => {
        const dialog = event.target.closest('[data-team-review-dialog]');
        if (dialog && event.target===dialog) dialog.close();
    });
    form?.addEventListener('submit', async event => {
        event.preventDefault();
        if (!textarea?.value.trim() || busy || textarea.disabled) return;
        busy = true; send.disabled=true; showError('');
        try {
            const response = await fetch(form.action, {method:'POST',credentials:'same-origin',
                headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest','X-CSRF-TOKEN':csrf},
                body:new FormData(form)});
            const data=await response.json().catch(()=>({}));
            if (!response.ok) throw new Error(data.message||'Не удалось отправить сообщение.');
            textarea.value=''; await refresh();
        } catch(e){ showError(e.message); }
        finally{busy=false;send.disabled=textarea.disabled;}
    });
    textarea?.addEventListener('keydown', e => {
        if(e.key==='Enter'&&(e.metaKey||e.ctrlKey)&&!e.isComposing){
            e.preventDefault();form.requestSubmit();
        }
    });
    const header = document.querySelector('[data-app-header][data-notification-user-id]');
    if (header) subscribePrivate('users.'+header.dataset.notificationUserId,
        '.notification.created', () => {void refresh();});
    window.addEventListener('focus', ()=>void refresh());
    document.addEventListener('visibilitychange',()=>{if(!document.hidden)void refresh()});
    const poll = window.setInterval(()=>{if(!document.hidden)void refresh()},15000);
    window.addEventListener('pagehide',()=>window.clearInterval(poll),{once:true});
}
