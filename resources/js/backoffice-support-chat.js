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

        let syncPending = false;
        const sync = async () => {
            if (syncPending || document.hidden || !element.isConnected) return;
            syncPending = true;
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
                // Reverb/network may be temporarily unavailable. The next refresh retries.
            } finally { syncPending = false; }
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
        const onReconnect = (event) => {
            if (event.detail?.state === 'connected') void sync();
        };
        window.addEventListener('mskba:realtime-state', onReconnect);
        // Small, bounded fallback if a client misses Reverb: only while this
        // authenticated support chat is mounted and the tab is visible.
        const fallback = window.setInterval(() => { void sync(); }, 12000);
        subscriptions.set(livewireId, () => {
            window.clearInterval(fallback);
            window.removeEventListener('mskba:realtime-state', onReconnect);
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
document.addEventListener('livewire:initialized', attach);
document.addEventListener('livewire:navigating', cleanup);
window.addEventListener('pagehide', cleanup);
let pendingAttach = false;
const observer = new MutationObserver(() => {
    if (pendingAttach) return;
    pendingAttach = true;
    queueMicrotask(() => {
        pendingAttach = false;
        attach();
    });
});
const startObserver = () => observer.observe(document.body, { childList: true, subtree: true });
if (document.body) startObserver();
else document.addEventListener('DOMContentLoaded', startObserver, { once: true });
window.addEventListener('pagehide', () => observer.disconnect());
