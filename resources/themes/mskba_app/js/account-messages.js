// Persist read state for a single conversation and reconcile realtime counters.
const thread = document.querySelector('[data-system-thread-read-url], [data-conversation-read-url]');

if (thread && !document.querySelector('[data-chat-root]') && Number(thread.dataset.page || 1) === 1) {
    // A messenger opens on the most recent message, at the end of page 1.
    document.querySelector('.app-messages__bubble:last-child')?.scrollIntoView({
        block: 'end', behavior: 'auto',
    });
}

// The live messenger has its own visibility-aware acknowledgement lifecycle.
if (thread && !document.querySelector('[data-chat-root]')
    && (Number(thread.dataset.unreadCount || 0) > 0 || thread.hasAttribute('data-conversation-read-url'))) {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const error = document.querySelector('[data-message-read-error]');
    const read = async () => {
        try {
            const response = await fetch(thread.dataset.conversationReadUrl || thread.dataset.systemThreadReadUrl, {
                method: 'PATCH',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            if (!response.ok) throw new Error('Не удалось отметить сообщения прочитанными.');
            const result = await response.json();
            document.querySelectorAll('.app-messages__bubble.is-unread').forEach(bubble => {
                bubble.classList.remove('is-unread');
                bubble.querySelector('.app-messages__new-label')?.remove();
            });
            document.dispatchEvent(new CustomEvent('notification:count-changed', {
                detail: { unread_count: result.unread_count },
            }));
        } catch {
            if (error) {
                error.textContent = 'Не удалось отметить сообщения прочитанными. Обнови страницу и попробуй ещё раз.';
                error.hidden = false;
            }
        }
    };
    void read();
}
