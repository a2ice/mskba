// Ephemeral action confirmations use the SAME DOM stack and design-system
// toasts as Reverb notifications. Unlike notifications they are not persisted,
// marked read, or included in the unread counter.
export const MAX_ACTION_TOASTS = 3;

export function initActionToasts(doc = document, host = window) {
    let region = doc.querySelector('[data-message-toast-region]');
    if (!region) {
        region = doc.createElement('section');
        region.className = 'app-message-toast-region';
        region.dataset.messageToastRegion = '';
        region.hidden = true;
        region.setAttribute('role', 'region');
        region.setAttribute('aria-label', 'Уведомления');
        doc.body.append(region);
    }
    const live = new Set();
    const remove = (card) => {
        if (!live.delete(card)) return;
        card.remove();
        // Keep the region visible while ordinary Reverb notifications remain.
        if (!region.children.length) region.hidden = true;
    };
    const emit = (event) => {
        const message = typeof event.detail?.message === 'string'
            ? event.detail.message.trim() : '';
        if (!message) return;
        const card = doc.createElement('article');
        card.className = 'toast app-message-toast app-action-toast' +
            (event.detail?.failed ? ' app-action-toast--error' : '');
        card.setAttribute('role', event.detail?.failed ? 'alert' : 'status');

        const title = doc.createElement('strong');
        title.className = 'app-message-toast__heading';
        title.textContent = message;

        const close = doc.createElement('button');
        close.type = 'button';
        close.className = 'app-message-toast__close';
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
