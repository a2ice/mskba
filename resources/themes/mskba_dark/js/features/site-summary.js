const summaryUrl = document.body?.dataset.siteSummaryUrl;
const todayEventsLinks = document.querySelectorAll('[data-today-events-link]');
const todayEventsEmptyTargets = document.querySelectorAll('[data-today-events-empty]');
const onlineTargets = document.querySelectorAll('[data-online-users-count]');
const onlineSummaries = document.querySelectorAll('[data-online-summary]');
const mobileStats = document.querySelectorAll('[data-mobile-summary-stats]');

if (summaryUrl && (todayEventsLinks.length || onlineTargets.length)) {
    let requestInProgress = false;

    const refresh = async () => {
        if (requestInProgress || document.visibilityState === 'hidden') {
            return;
        }

        requestInProgress = true;

        try {
            const response = await fetch(summaryUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                credentials: 'same-origin',
                body: '{}',
            });

            if (!response.ok) {
                return;
            }

            const summary = await response.json();

            todayEventsLinks.forEach((target) => {
                target.textContent = summary.today_events_text;
                target.hidden = summary.today_events === 0;
            });
            todayEventsEmptyTargets.forEach((target) => {
                target.hidden = summary.today_events > 0;
            });
            onlineTargets.forEach((target) => {
                target.textContent = summary.online_users;
            });
            onlineSummaries.forEach((target) => {
                target.hidden = summary.online_users === 0;
            });
            mobileStats.forEach((target) => {
                target.classList.toggle('has-online', summary.online_users > 0);
            });
        } catch {
            // Server-rendered values remain visible while the heartbeat is unavailable.
        } finally {
            requestInProgress = false;
        }
    };

    const heartbeatInterval = Number(document.body.dataset.siteSummaryHeartbeatInterval) || 45;
    const intervalId = window.setInterval(refresh, Math.max(30, heartbeatInterval) * 1000);

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            refresh();
        }
    });

    window.addEventListener('pagehide', () => window.clearInterval(intervalId), { once: true });
}
