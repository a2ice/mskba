// Presence is an advisory, opt-in-by-auth session signal. Reverb transports
// message invalidations, not repetitive heartbeats. No query strings/cookies.
const header = document.querySelector('[data-app-header][data-notification-user-id]');
if (header && window.location.protocol === 'https:') {
    const interval = 60_000;
    let lastSent = 0;
    let inFlight = false;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;

    const heartbeat = async () => {
        if (document.hidden || !csrf || inFlight || Date.now() - lastSent < 30_000) return;
        inFlight = true;
        lastSent = Date.now();
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
                body: JSON.stringify({ path: window.location.pathname }),
                cache: 'no-store',
            });
        } catch {
            // Never interrupt navigation, even when Redis or the network is down.
        } finally {
            inFlight = false;
        }
    };

    void heartbeat();
    const timer = window.setInterval(heartbeat, interval);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) void heartbeat();
    });
    window.addEventListener('pagehide', () => window.clearInterval(timer), { once: true });
}
