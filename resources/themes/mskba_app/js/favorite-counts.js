// Header favorites: the server owns the count. Reverb, action responses, focus
// and cross-tab hints all converge on the authenticated no-store endpoint.
import { subscribePrivate } from '../../../js/realtime.js';
import { normalizeFavoriteCount, formatFavoriteCount, canApplyFavoriteSync } from './favorite-counts-core.js';

const link = document.querySelector('[data-header-favorites]');
const badge = link?.querySelector('[data-favorites-count]');

if (link && badge) {
    const userId = link.dataset.favoritesUserId;
    const syncUrl = link.dataset.favoritesSyncUrl;
    const storageKey = 'mskba:favorites:count:' + (userId || 'guest');
    let latest = normalizeFavoriteCount(badge.dataset.count) ?? 0;
    let revision = 0;
    let syncSerial = 0;
    let channel = null;

    try {
        if (typeof BroadcastChannel !== 'undefined') {
            channel = new BroadcastChannel(storageKey);
        }
    } catch { /* Cross-tab sync remains available through storage/Reverb. */ }

    const render = (raw, announce = false) => {
        const count = normalizeFavoriteCount(raw);
        if (count === null) return false;
        const changed = latest !== count;
        latest = count;
        revision += 1;
        badge.textContent = formatFavoriteCount(count);
        badge.dataset.count = String(count);
        badge.hidden = count === 0;
        link.setAttribute('aria-label', 'Избранное: ' + count + (userId ? '' : ' — войти в аккаунт'));
        link.title = 'Избранное: ' + count + (userId ? '' : '. Войти и открыть');
        if (announce && changed) {
            try { channel?.postMessage({ count }); } catch { /* unavailable */ }
            try { localStorage.setItem(storageKey, JSON.stringify({count, at: Date.now()})); }
            catch { /* storage may be disabled */ }
        }
        return true;
    };

    const synchronize = async () => {
        if (!syncUrl) return; // Guest counts come from cookie-scoped SSR and successful toggles.
        const startedRevision = revision;
        const serial = ++syncSerial;
        try {
            const response = await fetch(syncUrl, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                cache: 'no-store',
            });
            if (!response.ok) return;
            const payload = await response.json();
            if (!canApplyFavoriteSync(startedRevision, revision, serial, syncSerial)) return;
            render(payload.count);
        } catch { /* Leave the last known count visible while offline. */ }
    };

    const applyRemote = raw => {
        if (render(raw)) void synchronize();
    };

    channel?.addEventListener('message', event => applyRemote(event.data?.count));
    window.addEventListener('storage', event => {
        if (event.key !== storageKey || !event.newValue) return;
        try { applyRemote(JSON.parse(event.newValue).count); } catch { /* malformed */ }
    });

    window.addEventListener('mskba:favorites:changed', event => {
        // Only successful mutations emit this event; never optimistically count
        // clicks while the request may still fail or be rejected.
        render(event.detail?.count, true);
        void synchronize();
    });

    if (userId) {
        subscribePrivate('users.' + userId, '.favorites.count-updated', payload => {
            render(payload?.count);
            void synchronize();
        });
    }
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) void synchronize();
    });
    window.addEventListener('focus', () => void synchronize());
    window.addEventListener('mskba:realtime-state', event => {
        if (event.detail?.state === 'connected') void synchronize();
    });

    render(latest);
    void synchronize();
}
