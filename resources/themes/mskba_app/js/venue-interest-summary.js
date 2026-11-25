// Keep the on-venue aggregate in sync after saving a subscription without
// showing other users' identities or adding a public user-discovery API.
const venueSummary = document.querySelector('[data-play-venue-interest-summary]');
if (venueSummary) {
    let latestRequest = 0;
    async function refreshAudience() {
        const request = ++latestRequest;
        try {
            const response = await fetch(location.pathname, {
                credentials: 'same-origin', cache: 'no-store',
                headers: { Accept: 'text/html' },
            });
            if (!response.ok) return;
            const html = new DOMParser().parseFromString(await response.text(), 'text/html');
            const updated = html.querySelector('[data-play-venue-interest-summary]');
            if (updated && request === latestRequest) {
                venueSummary.replaceChildren(...Array.from(updated.childNodes).map(node => document.importNode(node, true)));
                const freshButtons = [...html.querySelectorAll('[data-venue-interest-action]')];
                document.querySelectorAll('[data-venue-interest-action]').forEach((button, i) => {
                    const fresh = freshButtons[i];
                    if (!fresh) return;
                    button.textContent = fresh.textContent;
                    button.dataset.interestId = fresh.dataset.interestId || '';
                    button.dataset.cancelUrl = fresh.dataset.cancelUrl || '';
                });
                const total = html.querySelector('.app-venue-interests-dialog__total');
                const liveTotal = document.querySelector('.app-venue-interests-dialog__total');
                if (total && liveTotal) liveTotal.replaceChildren(...[...total.childNodes].map(node => document.importNode(node, true)));
                const freshList = html.querySelector('.app-venue-interests-dialog__list');
                const liveList = document.querySelector('.app-venue-interests-dialog__list');
                if (freshList && liveList) liveList.replaceChildren(...[...freshList.childNodes].map(node => document.importNode(node, true)));
            }
        } catch {
            // The underlying favorite or subscription is already saved; reload also refreshes.
        }
    }
    window.addEventListener('mskba:play-intent:saved', event => {
        if (event.detail?.id) void refreshAudience();
    });
    // Favorites and play-interest subscriptions are separate. Refreshing the
    // subscription HTML after a favorite toggle would replace an already bound
    // favorite button, losing its listeners and synchronized saved state.
    // The shared favorite controller repaints every visible favorite CTA.
}
