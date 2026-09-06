const summaryUrl = document.body?.dataset.siteSummaryUrl;
const todayEventsLinks = document.querySelectorAll('[data-today-events-link]');
const todayEventsEmptyTargets = document.querySelectorAll('[data-today-events-empty]');
const onlineTargets = document.querySelectorAll('[data-online-users-count]');
const onlineVisitorTargets = document.querySelectorAll('[data-online-visitors-count]');
const onlineSummaries = document.querySelectorAll('[data-online-summary]');
const mobileOnlineSummaries = document.querySelectorAll('[data-mobile-online-summary]');
const mobileStats = document.querySelectorAll('[data-mobile-summary-stats]');
const onlineTooltipTargets = [...new Set([...onlineSummaries, ...mobileOnlineSummaries])];

const onlineTooltipText = 'Авторизованные / всего онлайн';
let activeTooltip = null;
let activeTooltipTarget = null;

const hideOnlineTooltip = () => {
    activeTooltip?.remove();
    activeTooltip = null;
    activeTooltipTarget = null;
};

const showOnlineTooltip = (target) => {
    hideOnlineTooltip();

    const tooltip = document.createElement('div');
    tooltip.id = 'site-online-summary-tooltip';
    tooltip.setAttribute('role', 'tooltip');
    tooltip.textContent = onlineTooltipText;
    Object.assign(tooltip.style, {
        position: 'fixed',
        zIndex: '10000',
        padding: '7px 9px',
        border: '1px solid rgba(255, 255, 255, 0.16)',
        borderRadius: '8px',
        background: 'rgba(10, 12, 11, 0.98)',
        boxShadow: '0 8px 24px rgba(0, 0, 0, 0.38)',
        color: '#fff',
        fontSize: '11px',
        fontWeight: '600',
        lineHeight: '1.2',
        whiteSpace: 'nowrap',
        pointerEvents: 'none',
    });
    document.body.appendChild(tooltip);

    const targetRect = target.getBoundingClientRect();
    const tooltipRect = tooltip.getBoundingClientRect();
    const viewportPadding = 8;
    let left = targetRect.left + (targetRect.width / 2) - (tooltipRect.width / 2);
    left = Math.max(viewportPadding, Math.min(left, window.innerWidth - tooltipRect.width - viewportPadding));
    let top = targetRect.top - tooltipRect.height - 8;

    if (top < viewportPadding) {
        top = targetRect.bottom + 8;
    }

    tooltip.style.left = `${left}px`;
    tooltip.style.top = `${top}px`;
    target.setAttribute('aria-describedby', tooltip.id);
    activeTooltip = tooltip;
    activeTooltipTarget = target;
};

onlineTooltipTargets.forEach((target) => {
    // Do not use a native title here: the UI must stay icon-free while keeping an explanatory tooltip.
    target.removeAttribute('title');
    target.setAttribute('tabindex', '0');
    target.setAttribute('aria-label', onlineTooltipText);
    target.addEventListener('mouseenter', () => showOnlineTooltip(target));
    target.addEventListener('mouseleave', hideOnlineTooltip);
    target.addEventListener('focus', () => showOnlineTooltip(target));
    target.addEventListener('blur', hideOnlineTooltip);
    target.addEventListener('click', () => {
        if (window.matchMedia('(hover: none)').matches) {
            if (activeTooltipTarget === target) {
                hideOnlineTooltip();
            } else {
                showOnlineTooltip(target);
            }
        }
    });
});

if (summaryUrl && (todayEventsLinks.length || onlineTargets.length || onlineVisitorTargets.length)) {
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
            const onlineUsers = Number(summary.online_users) || 0;
            const onlineVisitors = Number(summary.online_visitors ?? summary.online_users) || 0;

            todayEventsLinks.forEach((target) => {
                target.textContent = summary.today_events_text;
                target.hidden = summary.today_events === 0;
            });
            todayEventsEmptyTargets.forEach((target) => {
                target.hidden = summary.today_events > 0;
            });
            onlineTargets.forEach((target) => {
                target.textContent = onlineUsers;
            });
            onlineVisitorTargets.forEach((target) => {
                target.textContent = onlineVisitors;
            });
            onlineSummaries.forEach((target) => {
                target.hidden = onlineVisitors === 0;
                const valueTarget = target.lastElementChild;

                if (valueTarget) {
                    valueTarget.textContent = `${onlineUsers}/${onlineVisitors} онлайн`;
                }
            });
            mobileOnlineSummaries.forEach((target) => {
                target.hidden = onlineVisitors === 0;
            });
            mobileStats.forEach((target) => {
                target.classList.toggle('has-online', onlineVisitors > 0);
            });
        } catch {
            // Server-rendered values remain visible while the heartbeat is unavailable.
        } finally {
            requestInProgress = false;
        }
    };

    refresh();

    const heartbeatInterval = Number(document.body.dataset.siteSummaryHeartbeatInterval) || 45;
    const intervalId = window.setInterval(refresh, Math.max(30, heartbeatInterval) * 1000);

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            refresh();
        }
    });

    window.addEventListener('pagehide', () => {
        window.clearInterval(intervalId);
        hideOnlineTooltip();
    }, { once: true });
}
