const summaryUrl = document.body?.dataset.siteSummaryUrl;
const todayEventsTargets = document.querySelectorAll('[data-today-events-text]');
const onlineTargets = document.querySelectorAll('[data-online-users-count]');
const totalTargets = document.querySelectorAll('[data-online-total-count]');

if (summaryUrl && (todayEventsTargets.length || onlineTargets.length || totalTargets.length)) {
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

            todayEventsTargets.forEach((target) => {
                target.textContent = summary.today_events_text;
            });
            onlineTargets.forEach((target) => {
                target.textContent = summary.online_users;
            });
            totalTargets.forEach((target) => {
                target.textContent = summary.total_users;
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
