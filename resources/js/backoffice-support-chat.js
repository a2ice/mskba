import { subscribePrivate } from './realtime.js';

// Only subscribe while a support record is mounted in Filament. Reverb carries
// an invalidation signal; the authorized Livewire component fetches content.
const subscriptions = new Map();

const attach = () => {
    const present = new Set();
    document.querySelectorAll('[data-filament-support-chat][data-support-thread-id]').forEach(element => {
        const threadId = element.dataset.supportThreadId;
        const livewireId = element.closest('[wire\\:id]')?.getAttribute('wire:id');
        if (!threadId || !livewireId) return;
        present.add(livewireId);
        if (subscriptions.has(livewireId)) return;

        const sync = async () => {
            try {
                const response = await fetch(element.dataset.supportStateUrl, {
                    credentials: 'same-origin', cache: 'no-store',
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (response.status === 403 || response.status === 404) {
                    window.location.assign('/backoffice/support-tickets');
                    return;
                }
                if (!response.ok) return;
                const component = window.Livewire?.find(livewireId);
                if (!component) return;
                await component.$wire.$call('refreshConversation');
            } catch (_error) {
                // Livewire or the network may be temporarily unavailable.
            }
        };
        const viewport = element.querySelector('[data-filament-support-history]');
        let stickToBottom = true;
        let olderSnapshot = null;
        const onScroll = () => {
            stickToBottom = viewport.scrollHeight - viewport.scrollTop - viewport.clientHeight < 90;
        };
        const onClick = (event) => {
            if (event.target.closest('button')?.getAttribute('wire:click') === 'loadOlder') {
                olderSnapshot = { top: viewport.scrollTop, height: viewport.scrollHeight };
                stickToBottom = false;
            }
        };
        const observer = new MutationObserver(() => {
            if (olderSnapshot) {
                viewport.scrollTop = olderSnapshot.top + viewport.scrollHeight - olderSnapshot.height;
                olderSnapshot = null;
            } else if (stickToBottom) {
                viewport.scrollTop = viewport.scrollHeight;
            }
        });
        if (viewport) {
            viewport.addEventListener('scroll', onScroll, { passive: true });
            viewport.addEventListener('click', onClick);
            observer.observe(viewport, { childList: true });
            requestAnimationFrame(() => { viewport.scrollTop = viewport.scrollHeight; });
        }
        const onSent = () => { if (viewport) viewport.scrollTop = viewport.scrollHeight; };
        window.addEventListener('support-chat-sent', onSent);
        const unsubscribe = subscribePrivate('support-tickets.' + threadId, '.conversation.changed', () => {
            void sync();
        });
        subscriptions.set(livewireId, () => {
            unsubscribe();
            observer.disconnect();
            viewport?.removeEventListener('scroll', onScroll);
            viewport?.removeEventListener('click', onClick);
            window.removeEventListener('support-chat-sent', onSent);
        });
    });
    for (const [id, unsubscribe] of subscriptions) {
        if (present.has(id)) continue;
        unsubscribe();
        subscriptions.delete(id);
    }
};

const cleanup = () => {
    for (const unsubscribe of subscriptions.values()) unsubscribe();
    subscriptions.clear();
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', attach, { once: true });
} else {
    attach();
}
document.addEventListener('livewire:navigated', attach);
document.addEventListener('livewire:navigating', cleanup);
window.addEventListener('pagehide', cleanup);
