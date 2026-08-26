import { realtimeState, subscribePrivate } from '../../../../js/realtime.js';

document.addEventListener('DOMContentLoaded', () => {
    const region = document.querySelector('#booking-conversation-messages');
    const form = document.querySelector('#booking-conversation-form');
    if (!region || !form) return;

    let lastId = Math.max(0, ...Array.from(region.querySelectorAll('[data-message-id]')).map((node) => Number(node.dataset.messageId)));
    let unsubscribe = () => {};

    const render = (message, pending = false) => {
        const existing = region.querySelector(`[data-client-id="${CSS.escape(message.client_id)}"]`);
        const article = existing || document.createElement('article');
        article.className = 'border-top py-2';
        article.dataset.clientId = message.client_id;
        if (message.id) article.dataset.messageId = message.id;
        article.replaceChildren();
        const author = document.createElement('strong');
        author.textContent = message.author || 'Вы';
        const body = document.createElement('p');
        body.textContent = message.body || '';
        article.append(author, body);
        if (pending) article.setAttribute('aria-label', 'Сообщение отправляется');
        else article.removeAttribute('aria-label');
        if (!existing) region.append(article);
        lastId = Math.max(lastId, Number(message.id || 0));
    };

    const poll = async () => {
        const separator = region.dataset.pollUrl.includes('?') ? '&' : '?';
        const response = await fetch(`${region.dataset.pollUrl}${separator}after_id=${lastId}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!response.ok) return;
        const payload = await response.json();
        payload.messages.forEach((message) => render(message));
        if (!region.dataset.conversationId && payload.conversation_id) subscribe(payload.conversation_id);
    };

    const subscribe = (conversationId) => {
        if (!conversationId) return;
        region.dataset.conversationId = conversationId;
        unsubscribe();
        unsubscribe = subscribePrivate(`venue-booking-conversations.${conversationId}`, '.booking.message.sent', poll);
    };

    subscribe(region.dataset.conversationId);
    window.addEventListener('mskba:realtime-state', (event) => {
        if (event.detail.state === 'connected') poll();
    });
    window.setInterval(() => {
        if (realtimeState() !== 'connected') poll();
    }, 15000);

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const data = new FormData(form);
        const optimistic = { client_id: data.get('client_id'), body: data.get('body'), author: 'Вы' };
        render(optimistic, true);
        const response = await fetch(form.action, { method: 'POST', body: data, headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!response.ok) {
            form.submit();
            return;
        }
        const message = await response.json();
        render(message);
        subscribe(message.conversation_id);
        form.querySelector('[name="body"]').value = '';
        form.querySelector('[name="client_id"]').value = crypto.randomUUID();
    });
});
