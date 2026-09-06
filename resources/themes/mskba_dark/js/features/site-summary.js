const summaryUrl = document.body?.dataset.siteSummaryUrl;
const todayEventsLinks = document.querySelectorAll('[data-today-events-link]');
const todayEventsEmptyTargets = document.querySelectorAll('[data-today-events-empty]');
const onlineTargets = document.querySelectorAll('[data-online-users-count]');
const onlineVisitorTargets = document.querySelectorAll('[data-online-visitors-count]');
const onlineSummaries = document.querySelectorAll('[data-online-summary]');
const mobileOnlineSummaries = document.querySelectorAll('[data-mobile-online-summary]');
const mobileStats = document.querySelectorAll('[data-mobile-summary-stats]');
const onlineTooltipTargets = [...new Set([...onlineSummaries, ...mobileOnlineSummaries])];
const createEventTooltipTargets = document.querySelectorAll('.home-welcome__badges .home-status-action');
const desktopHomeBadges = document.querySelector('.home-welcome__badges');
const heroPlayButton = document.querySelector('.home-welcome__actions .home-cta.btn--primary');

const onlineTooltipText = 'Авторизованные / всего онлайн';
const createEventTooltipText = 'Создать мероприятие';
let activeTooltip = null;
let activeTooltipTarget = null;

const hideSiteTooltip = () => {
    if (activeTooltipTarget) {
        activeTooltipTarget.removeAttribute('aria-describedby');
    }

    activeTooltip?.remove();
    activeTooltip = null;
    activeTooltipTarget = null;
};

const showSiteTooltip = (target, text) => {
    hideSiteTooltip();

    const tooltip = document.createElement('div');
    tooltip.id = 'site-summary-tooltip';
    tooltip.setAttribute('role', 'tooltip');
    tooltip.textContent = text;
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

const bindSiteTooltip = (target, text, { label = false } = {}) => {
    // Keep the tooltip custom and icon-free instead of relying on a native title.
    target.removeAttribute('title');

    if (!target.matches('button, a, input, select, textarea, [tabindex]')) {
        target.setAttribute('tabindex', '0');
    }

    if (label) {
        target.setAttribute('aria-label', text);
    }

    target.addEventListener('mouseenter', () => showSiteTooltip(target, text));
    target.addEventListener('mouseleave', hideSiteTooltip);
    target.addEventListener('focus', () => showSiteTooltip(target, text));
    target.addEventListener('blur', hideSiteTooltip);
    target.addEventListener('click', () => {
        if (window.matchMedia('(hover: none)').matches) {
            if (activeTooltipTarget === target) {
                hideSiteTooltip();
            } else {
                showSiteTooltip(target, text);
            }
        }
    });
};

onlineTooltipTargets.forEach((target) => bindSiteTooltip(target, onlineTooltipText, { label: true }));
createEventTooltipTargets.forEach((target) => bindSiteTooltip(target, createEventTooltipText));

if (desktopHomeBadges) {
    const desktopBadgesMedia = window.matchMedia('(min-width: 901px)');
    const syncDesktopBadgeGap = () => {
        desktopHomeBadges.style.gap = desktopBadgesMedia.matches ? '36px' : '';
    };

    syncDesktopBadgeGap();
    desktopBadgesMedia.addEventListener?.('change', syncDesktopBadgeGap);
}

if (heroPlayButton) {
    const heroPlayIcons = heroPlayButton.querySelectorAll(':scope > i');

    if (heroPlayIcons.length > 1) {
        heroPlayIcons[heroPlayIcons.length - 1].remove();
    }
}

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
        hideSiteTooltip();
    }, { once: true });
}