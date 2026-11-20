// Ephemeral action confirmations use the SAME DOM stack and design-system
// toasts as Reverb notifications. Unlike notifications they are not persisted,
// marked read, or included in the unread counter.
export const MAX_ACTION_TOASTS = 3;

export function initActionToasts(doc = document, host = window, { legacy = false } = {}) {
    let region = doc.querySelector(legacy ? '[data-notification-toasts]' : '[data-message-toast-region]');
    const persistentLegacy = legacy && Boolean(region);
    if (!region) {
        region = doc.createElement('section');
        region.className = legacy ? 'notification-toast-region' : 'app-message-toast-region';
        if (!legacy) region.dataset.messageToastRegion = '';
        region.hidden = true;
        region.setAttribute('role', 'region');
        region.setAttribute('aria-label', 'Уведомления');
        doc.body.append(region);
    }
    const live = new Set();
    let restoreRegion = null;
    const mountForDialog = (dialog) => {
        if (!dialog?.open || dialog.contains(region)) return;
        restoreRegion?.();
        const parent = region.parentNode;
        const next = region.nextSibling;
        const restore = () => {
            delete region.dataset.actionToastModal;
            if (next?.parentNode === parent) parent.insertBefore(region, next);
            else parent.append(region);
            dialog.removeEventListener('close', restore);
            restoreRegion = null;
        };
        restoreRegion = restore;
        region.dataset.actionToastModal = '';
        dialog.append(region);
        dialog.addEventListener('close', restore, { once: true });
    };
    const remove = (card) => {
        if (!live.delete(card)) return;
        card.remove();
        // Keep the region visible while ordinary Reverb notifications remain.
        if (!region.children.length && !persistentLegacy) region.hidden = true;
        if (!live.size) restoreRegion?.();
        if (persistentLegacy) doc.dispatchEvent(new CustomEvent('mskba:action-toast-removed'));
    };
    const emit = (event) => {
        const message = typeof event.detail?.message === 'string'
            ? event.detail.message.trim() : '';
        if (!message) return;
        mountForDialog(event.detail?.dialog);
        const card = doc.createElement('article');
        card.className = (legacy ? 'notification-toast app-action-toast' : 'toast app-message-toast app-action-toast') +
            (event.detail?.failed ? ' app-action-toast--error' : '');
        card.setAttribute('role', event.detail?.failed ? 'alert' : 'status');

        const title = doc.createElement('strong');
        title.className = legacy ? 'notification-toast__title' : 'app-message-toast__heading';
        title.textContent = message;

        const close = doc.createElement('button');
        close.type = 'button';
        close.className = legacy ? 'notification-toast__close' : 'app-message-toast__close';
        close.setAttribute('aria-label', 'Скрыть уведомление');
        close.textContent = '×';
        close.addEventListener('click', () => remove(card));
        card.append(title, close);
        live.add(card);
        region.prepend(card);
        region.hidden = false;

        // Limit only ephemeral actions, never remove persistent Reverb notices.
        while (live.size > MAX_ACTION_TOASTS) remove(live.values().next().value);
        host.setTimeout(() => remove(card), event.detail?.failed ? 8000 : 6000);
    };
    host.addEventListener('mskba:action-toast', emit);
    return { region, emit, remove, size: () => live.size };
}
