// Keep the on-venue aggregate in sync after saving an interest without
// showing other users' identities or adding a public user-discovery API.
const venueSummary = document.querySelector('[data-play-venue-interest-summary]');
if (venueSummary) {
    let latestRequest = 0;
    window.addEventListener('mskba:play-intent:saved', async (event) => {
        const root = document.querySelector('[data-mskba-play-intent-root][data-options]');
        if (!root) return;
        let currentVenueId = null;
        try { currentVenueId = JSON.parse(root.dataset.options).venueId; } catch { return; }
        if (Number(event.detail?.venueId) !== Number(currentVenueId)) return;

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
            }
        } catch {
            // The interest is already saved; the aggregate updates on reload.
        }
    });
}
