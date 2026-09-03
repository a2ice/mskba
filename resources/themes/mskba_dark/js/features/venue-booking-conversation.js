import {
    realtimeSocketId,
    realtimeState,
    subscribePrivate,
} from '../../../../js/realtime.js';

document.addEventListener('DOMContentLoaded', () => {
    const page = document.querySelector('[data-venue-booking-page]');
    const modal = document.querySelector('[data-modal="venue-booking-conversation"]');
    const region = document.querySelector('#booking-conversation-messages');
    const messageForm = document.querySelector('#booking-conversation-form');
    const attachmentForm = document.querySelector('[data-booking-conversation-attachment]');

    if (!page) return;

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const bookingId = page.dataset.bookingId;
    let bookingVersion = Number(page.dataset.bookingVersion || 0);
    let lastId = region
        ? Math.max(0, ...Array.from(region.querySelectorAll('[data-message-id]')).map((node) => Number(node.dataset.messageId || 0)))
        : 0;
    let latestMessagePublicId = region?.querySelector('[data-message-public-id]:last-of-type')?.dataset.messagePublicId || null;
    let conversationId = region?.dataset.conversationId || null;
    let conversationUnsubscribe = () => {};
    let reloadScheduled = false;

    const jsonHeaders = () => {
        const headers = { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken };
        const socketId = realtimeSocketId();
        if (socketId) headers['X-Socket-ID'] = socketId;
        return headers;
    };

    const isModalOpen = () => modal?.classList.contains('is-open') === true;

    const setStatus = (message, error = false) => {
        const status = modal?.querySelector('[data-booking-conversation-status]');
        if (!status) return;
        status.textContent = message;
        status.classList.toggle('is-error', error);
    };

    const setUnread = (count) => {
        const normalized = Math.max(0, Number(count || 0));
        document.querySelectorAll('[data-booking-unread-count]').forEach((node) => {
            node.textContent = String(normalized);
        });
        document.querySelectorAll('[data-booking-unread-wrap]').forEach((node) => {
            node.hidden = normalized === 0;
        });
        document.querySelectorAll('[data-booking-message-notice]').forEach((node) => {
            node.classList.toggle('is-unread', normalized > 0);
        });
        document.querySelectorAll('[data-booking-message-notice-label]').forEach((node) => {
            node.textContent = normalized > 0 ? 'Новые сообщения' : 'Переписка по заявке';
        });
    };

    const setRequesterIdentity = (requester) => {
        if (!requester) return;

        const button = document.querySelector('.venue-booking-applicant__button');
        const fallbackName = document.querySelector('.venue-booking-applicant > strong');
        const nameNode = button
            ? Array.from(button.children).find((node) => node.tagName === 'SPAN' && !node.hasAttribute('data-booking-unread-wrap'))
            : fallbackName;

        if (nameNode && requester.name) {
            nameNode.textContent = requester.name;
        }

        const modalCounterparty = document.querySelector('.venue-booking-applicant')
            ? modal?.querySelector('.venue-booking-conversation-modal__heading > p:last-child')
            : null;
        if (modalCounterparty && requester.name) {
            modalCounterparty.textContent = requester.name;
        }

        if (!button || !requester.avatar_url) return;

        let avatar = button.querySelector('.venue-booking-applicant__avatar');
        if (!avatar) {
            avatar = document.createElement('img');
            avatar.className = 'venue-booking-applicant__avatar';
            avatar.alt = '';
            const icon = button.querySelector('.ti-user');
            if (icon) icon.replaceWith(avatar);
            else button.prepend(avatar);
        }
        avatar.src = requester.avatar_url;
    };

    const scheduleReload = () => {
        if (reloadScheduled) return;
        reloadScheduled = true;
        window.setTimeout(() => window.location.reload(), 180);
    };

    const attachmentUrl = (messageId) => `${region.dataset.attachmentUrlBase}/${encodeURIComponent(messageId)}/attachment`;

    const render = (message, pending = false) => {
        if (!region || !message) return;
        const clientId = message.client_id || '';
        const existing = clientId
            ? region.querySelector(`[data-client-id="${CSS.escape(clientId)}"]`)
            : region.querySelector(`[data-message-id="${Number(message.id || 0)}"]`);
        const article = existing || document.createElement('article');
        const isOwn = pending || message.is_own === true;
        article.className = `venue-booking-message ${isOwn ? 'is-own' : 'is-incoming'}`;
        if (clientId) article.dataset.clientId = clientId;
        if (message.id) article.dataset.messageId = String(message.id);
        if (message.message_id) article.dataset.messagePublicId = message.message_id;
        article.replaceChildren();
        article.setAttribute('aria-label', pending
            ? 'Исходящее сообщение отправляется'
            : (isOwn ? 'Исходящее сообщение' : 'Входящее сообщение'));

        if (message.created_at) {
            const header = document.createElement('div');
            header.className = 'venue-booking-message__header';
            const time = document.createElement('time');
            const date = new Date(message.created_at);
            time.dateTime = message.created_at;
            time.textContent = Number.isNaN(date.getTime())
                ? ''
                : date.toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' });
            header.append(time);
            article.append(header);
        }

        if (message.body) {
            const body = document.createElement('p');
            body.textContent = message.body;
            article.append(body);
        }
        if (message.attachment && message.message_id) {
            const link = document.createElement('a');
            link.href = attachmentUrl(message.message_id);
            link.textContent = `Скачать: ${message.attachment.name}`;
            article.append(link);
        }
        article.classList.toggle('is-pending', pending);
        region.querySelector('[data-booking-conversation-empty]')?.remove();
        if (!existing) region.append(article);
        lastId = Math.max(lastId, Number(message.id || 0));
        latestMessagePublicId = message.message_id || latestMessagePublicId;
        region.scrollTop = region.scrollHeight;
    };

    const markRead = async () => {
        if (!region || !conversationId || !latestMessagePublicId || !isModalOpen()) return;
        const body = new FormData();
        body.set('message_id', latestMessagePublicId);
        const response = await fetch(`${region.dataset.readUrlBase}/${conversationId}/read`, {
            method: 'POST',
            body,
            headers: jsonHeaders(),
            credentials: 'same-origin',
        });
        if (response.ok) setUnread(0);
    };

    const subscribeConversation = (nextConversationId) => {
        if (!nextConversationId || (nextConversationId === conversationId && region?.dataset.conversationSubscribed === '1')) return;
        conversationId = nextConversationId;
        if (region) {
            region.dataset.conversationId = nextConversationId;
            region.dataset.conversationSubscribed = '1';
        }
        conversationUnsubscribe();
        conversationUnsubscribe = subscribePrivate(
            `venue-booking-conversations.${nextConversationId}`,
            '.booking.message.sent',
            () => pollConversation(),
        );
    };

    const pollConversation = async (hydrate = false) => {
        if (!region) return;
        const separator = region.dataset.pollUrl.includes('?') ? '&' : '?';
        const url = hydrate
            ? region.dataset.pollUrl
            : `${region.dataset.pollUrl}${separator}after_id=${lastId}`;
        const response = await fetch(url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (!response.ok) return;
        const payload = await response.json();
        payload.messages.forEach((message) => render(message));
        if (payload.conversation_id) subscribeConversation(payload.conversation_id);
        if (payload.latest_message_id) latestMessagePublicId = payload.latest_message_id;
        if (isModalOpen()) await markRead();
        else setUnread(payload.unread_count);
    };

    const pollBooking = async () => {
        const response = await fetch(page.dataset.bookingDetailsUrl, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (!response.ok) return;
        const payload = await response.json();
        setRequesterIdentity(payload.requester);
        const nextVersion = Number(payload.version || 0);
        if (nextVersion > bookingVersion) {
            bookingVersion = nextVersion;
            scheduleReload();
            return;
        }
        if (!isModalOpen()) setUnread(payload.conversation?.unread_count || 0);
        if (payload.conversation?.conversation_id) subscribeConversation(payload.conversation.conversation_id);
    };

    const submitForm = async (form, optimistic = false) => {
        const data = new FormData(form);
        const pending = optimistic ? {
            client_id: data.get('client_id'),
            body: data.get('body'),
            is_own: true,
        } : null;
        if (pending) render(pending, true);
        setStatus('Отправляем…');
        const response = await fetch(form.action, {
            method: 'POST',
            body: data,
            headers: jsonHeaders(),
            credentials: 'same-origin',
        });
        if (!response.ok) {
            setStatus('Не удалось отправить сообщение. Попробуйте ещё раз.', true);
            if (pending) region?.querySelector(`[data-client-id="${CSS.escape(String(pending.client_id))}"]`)?.remove();
            return;
        }
        const message = await response.json();
        render(message);
        subscribeConversation(message.conversation_id);
        form.reset();
        const clientId = form.querySelector('[name="client_id"]');
        if (clientId) clientId.value = crypto.randomUUID();
        setStatus('Сообщение отправлено.');
        await markRead();
    };

    subscribePrivate(`venue-bookings.${bookingId}`, '.booking.updated', (payload) => {
        if (Number(payload.version || 0) > bookingVersion) scheduleReload();
    });
    subscribePrivate(`venue-bookings.${bookingId}`, '.booking.message.sent', (payload) => {
        if (payload.conversation_id) subscribeConversation(payload.conversation_id);
        pollConversation();
    });
    subscribeConversation(conversationId);
    pollBooking();
    pollConversation(true);

    window.addEventListener('mskba:realtime-state', (event) => {
        if (event.detail.state === 'connected') {
            pollBooking();
            pollConversation();
        }
    });
    window.setInterval(() => {
        pollBooking();
        if (realtimeState() !== 'connected' || isModalOpen()) pollConversation();
    }, 15000);

    const modalObserver = modal ? new MutationObserver(() => {
        if (isModalOpen()) {
            pollConversation();
            window.setTimeout(() => region?.scrollTo({ top: region.scrollHeight }), 0);
        }
    }) : null;
    if (modal && modalObserver) modalObserver.observe(modal, { attributes: true, attributeFilter: ['class', 'hidden'] });

    if (window.location.hash === '#booking-conversation') {
        document.querySelector('[data-booking-message-notice], .venue-booking-applicant__button')?.click();
    }

    messageForm?.addEventListener('submit', (event) => {
        event.preventDefault();
        submitForm(messageForm, true);
    });
    attachmentForm?.addEventListener('submit', (event) => {
        event.preventDefault();
        submitForm(attachmentForm);
    });
});
