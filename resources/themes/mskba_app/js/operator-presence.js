// Presence is an advisory, opt-in-by-auth session signal. Reverb transports
// message invalidations, not repetitive heartbeats. No query strings/cookies.
const header = document.querySelector('[data-app-header][data-notification-user-id]');
if (header && window.location.protocol === 'https:') {
    const interval = 60_000;
    let lastSent = 0;
    let lastPath = '';
    let inFlight = false;
    let pendingNavigation = null;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;

    const heartbeat = async () => {
        if (document.hidden || !csrf || inFlight) return;
        const path = window.location.pathname;
        const changedPage = path !== lastPath;
        // SPA navigation should update the operator promptly without turning
        // mouse clicks into unlimited Redis writes.
        const minimumDelay = changedPage ? 3_500 : 30_000;
        const wait = minimumDelay - (Date.now() - lastSent);
        if (lastSent && wait > 0) {
            if (changedPage && pendingNavigation === null) {
                pendingNavigation = window.setTimeout(() => {
                    pendingNavigation = null;
                    void heartbeat();
                }, wait + 50);
            }
            return;
        }
        inFlight = true;
        lastSent = Date.now();
        lastPath = path;
        try {
            await fetch('/account/presence/heartbeat', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrf,
                },
                body: JSON.stringify({ path }),
                cache: 'no-store',
            });
        } catch {
            // Never interrupt navigation, even when Redis or the network is down.
        } finally {
            inFlight = false;
            // A route may change while an earlier heartbeat is still in flight.
            if (!document.hidden && window.location.pathname !== lastPath) void heartbeat();
        }
    };

    void heartbeat();
    const timer = window.setInterval(heartbeat, interval);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) void heartbeat();
    });
    // Inertia changes pages without re-evaluating the app bundle. Ordinary
    // full-page navigation already runs heartbeat immediately on load.
    document.addEventListener('inertia:navigate', () => { void heartbeat(); });
    window.addEventListener('popstate', () => { void heartbeat(); });
    window.addEventListener('pagehide', () => {
        window.clearInterval(timer);
        if (pendingNavigation !== null) window.clearTimeout(pendingNavigation);
    }, { once: true });
}
