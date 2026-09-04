import {
    realtimeSocketId,
    realtimeState,
    subscribePrivate,
} from '../../../../js/realtime.js';

document.addEventListener('DOMContentLoaded', () => {
    const page = document.querySelector('[data-venue-ownership-claim-page]');
    if (!page) return;

    const claimId = page.dataset.claimId;
    const conversationUrl = page.dataset.conversationUrl;
    const messageUrl = page.dataset.messageUrl;
    const attachmentUrl = page.dataset.attachmentUrl;
    const attachmentDownloadBase = page.dataset.attachmentDownloadBase;
    const messagesRegion = page.querySelector('[data-ownership-messages]');
    const composer = page.querySelector('[data-ownership-composer]');
    const waiting = page.querySelector('[data-ownership-waiting]');
    const messageForm = page.querySelector('[data-ownership-message-form]');
    const attachmentForm = page.querySelector('[data-ownership-attachment-form]');
    const sendStatus = page.querySelector('[data-ownership-send-status]');
    const statusNode = page.querySelector('[data-ownership-status]');
    const statusLabel = page.querySelector('[data-ownership-status-label]');
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

    let lastId = 0;
    let conversationId = null;
    let conversationUnsubscribe = () => {};
    let reloadTimer = null;

    const headers = () => {
        const result = {
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken,
        };
        const socketId = realtimeSocketId();
        if (socketId) result['X-Socket-ID'] = socketId;
        return result;
    };

    const setSendStatus = (message, isError = false) => {
        if (!sendStatus) return;
        sendStatus.textContent = message || '';
        sendStatus.classList.toggle('is-error', isError);
    };

    const setWritable = (payload) => {
        if (!composer || !waiting) return;
        const writable = payload.can_reply === true || payload.can_start === true;
        composer.hidden = !writable;
        waiting.hidden = writable;
    };

    const updateStatus = (status, label) => {
        if (statusLabel && label) statusLabel.textContent = label;
        if (!statusNode || !status) return;
        Array.from(statusNode.classList)
            .filter((className) => className.startsWith('venue-ownership-live-status--'))
            .forEach((className) => statusNode.classList.remove(className));
        statusNode.classList.add(`venue-ownership-live-status--${status}`);

        if (status !== 'pending' && !reloadTimer) {
            reloadTimer = window.setTimeout(() => window.location.reload(), 650);
        }
    };

    const subscribeConversation = (id) => {
        if (!id || id === conversationId) return;
        conversationId = id;
        conversationUnsubscribe();
        conversationUnsubscribe = subscribePrivate(
            `venue-ownership-claim-conversations.${id}`,
            '.venue.ownership.message.sent',
            () => pollConversation(),
        );
    };

    const renderMessage = (message) => {
        if (!messagesRegion || !message) return;

        const messageId = Number(message.id || 0);
        const publicId = String(message.message_id || '');
        const clientId = String(message.client_id || '');
        const existing = publicId
            ? messagesRegion.querySelector(`[data-message-public-id="${CSS.escape(publicId)}"]`)
            : null;
        if (existing) {
            lastId = Math.max(lastId, messageId);
            return;
        }

        const article = document.createElement('article');
        article.className = `venue-ownership-message ${message.is_own ? 'is-own' : 'is-incoming'}`;
        if (publicId) article.dataset.messagePublicId = publicId;
        if (clientId) article.dataset.clientId = clientId;
        if (messageId) article.dataset.messageId = String(messageId);

        if (message.created_at) {
            const time = document.createElement('time');
            const date = new Date(message.created_at);
            time.dateTime = message.created_at;
            time.textContent = Number.isNaN(date.getTime())
                ? ''
                : date.toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' });
            article.append(time);
        }

        if (message.body) {
            const body = document.createElement('p');
            body.textContent = message.body;
            article.append(body);
        }

        if (message.attachment && publicId) {
            const link = document.createElement('a');
            link.className = 'venue-ownership-message__attachment';
            link.href = `${attachmentDownloadBase}/${encodeURIComponent(publicId)}/attachment`;
            link.textContent = `Скачать: ${message.attachment.name}`;
            article.append(link);
        }

        messagesRegion.querySelector('[data-ownership-empty]')?.remove();
        messagesRegion.append(article);
        lastId = Math.max(lastId, messageId);
        messagesRegion.scrollTop = messagesRegion.scrollHeight;
    };

    const applyPayload = (payload) => {
        if (!payload) return;
        updateStatus(payload.status, payload.status_label);
        setWritable(payload);
        if (payload.conversation_id) subscribeConversation(payload.conversation_id);
        (payload.messages || []).forEach(renderMessage);
    };

    const pollConversation = async () => {
        if (!conversationUrl) return;
        const separator = conversationUrl.includes('?') ? '&' : '?';
        const url = lastId > 0 ? `${conversationUrl}${separator}after_id=${lastId}` : conversationUrl;

        try {
            const response = await fetch(url, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!response.ok) return;
            applyPayload(await response.json());
        } catch (_) {
            // Realtime reconnect/polling will retry.
        }
    };

    const submitForm = async (form, url) => {
        if (!form || !url) return;
        setSendStatus('Отправляем…');
        const submitButton = form.querySelector('[type="submit"]');
        if (submitButton) submitButton.disabled = true;

        try {
            const response = await fetch(url, {
                method: 'POST',
                body: new FormData(form),
                headers: headers(),
                credentials: 'same-origin',
            });
            if (!response.ok) {
                const payload = await response.json().catch(() => ({}));
                throw new Error(payload.message || 'Не удалось отправить сообщение.');
            }

            const message = await response.json();
            renderMessage(message);
            subscribeConversation(message.conversation_id);
            composer.hidden = false;
            waiting.hidden = true;
            form.reset();
            const clientInput = form.querySelector('[name="client_id"]');
            if (clientInput) clientInput.value = crypto.randomUUID();
            setSendStatus('Отправлено.');
        } catch (error) {
            setSendStatus(error.message || 'Не удалось отправить сообщение.', true);
        } finally {
            if (submitButton) submitButton.disabled = false;
        }
    };

    messageForm?.addEventListener('submit', (event) => {
        event.preventDefault();
        submitForm(messageForm, messageUrl);
    });

    attachmentForm?.addEventListener('submit', (event) => {
        event.preventDefault();
        submitForm(attachmentForm, attachmentUrl);
    });

    subscribePrivate(`venue-ownership-claims.${claimId}`, '.venue.ownership.updated', (payload) => {
        updateStatus(payload.status, payload.status_label);
    });
    subscribePrivate(`venue-ownership-claims.${claimId}`, '.venue.ownership.message.sent', () => {
        pollConversation();
    });

    pollConversation();

    window.addEventListener('mskba:realtime-state', (event) => {
        if (event.detail.state === 'connected') pollConversation();
    });

    window.setInterval(() => {
        if (realtimeState() !== 'connected') pollConversation();
    }, 15000);
});
