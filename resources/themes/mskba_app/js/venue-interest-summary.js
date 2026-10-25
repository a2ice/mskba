// Keep the on-venue aggregate in sync after saving an interest without
// showing other users' identities or adding a public user-discovery API.
const venueSummary = document.querySelector('[data-play-venue-interest-summary]');
if (venueSummary) {
    let latestRequest = 0;
    window.addEventListener('mskba:play-intent:saved', async (event) => {
        const root = document.querySelector('[data-mskba-play-intent-root][data-options]');
        if (!root) return;
        // A detached interest changes the venue aggregate too: refresh even
        // when its new venue_id is null. Only this page listens to the event.
        if (!event.detail?.id) return;

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
                const freshList = html.querySelector('.app-venue-interests-dialog__list');
                const liveList = document.querySelector('.app-venue-interests-dialog__list');
                if (freshList && liveList) liveList.replaceChildren(...[...freshList.childNodes].map(node => document.importNode(node, true)));
            }
        } catch {
            // The interest is already saved; the aggregate updates on reload.
        }
    });
}
