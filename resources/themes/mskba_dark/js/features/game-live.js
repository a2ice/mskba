const liveScreen = document.querySelector('[data-game-live-screen]');

if (liveScreen) {
    document.body.classList.add('has-game-live-screen');

    const statsPanel = liveScreen.querySelector('[data-game-live-stats]');
    const statsOpen = liveScreen.querySelector('[data-game-live-stats-open]');
    const statsClose = liveScreen.querySelectorAll('[data-game-live-stats-close]');
    const eventOverlay = liveScreen.querySelector('[data-game-live-event]');
    const eventTeam = liveScreen.querySelector('[data-game-live-event-team]');
    const eventLabel = liveScreen.querySelector('[data-game-live-event-label]');
    const eventPlayer = liveScreen.querySelector('[data-game-live-event-player]');
    const eventLogo = liveScreen.querySelector('[data-game-live-event-logo]');
    const eventLogoFallback = liveScreen.querySelector('[data-game-live-event-logo-fallback]');
    const scoreNodes = Object.fromEntries(
        ['A', 'B'].map((slot) => [slot, liveScreen.querySelector(`[data-game-live-score="${slot}"]`)]),
    );
    let eventTimer = null;

    liveScreen.querySelectorAll('[data-game-live-team-logo]').forEach((logo) => {
        logo.addEventListener('error', () => {
            logo.hidden = true;
            logo.nextElementSibling.hidden = false;
        });
    });

    eventLogo?.addEventListener('error', () => {
        eventLogo.hidden = true;
        eventLogoFallback.hidden = false;
    });

    [statsPanel, eventOverlay].forEach((overlay) => {
        if (overlay) {
            document.body.append(overlay);
        }
    });

    const setStatsOpen = (isOpen) => {
        if (!statsPanel) {
            return;
        }

        statsPanel.hidden = !isOpen;
        statsOpen?.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    };

    statsOpen?.setAttribute('aria-expanded', 'false');
    statsOpen?.addEventListener('click', () => setStatsOpen(true));
    statsClose.forEach((button) => button.addEventListener('click', () => setStatsOpen(false)));

    const hideEvent = () => {
        if (!eventOverlay) {
            return;
        }

        eventOverlay.hidden = true;
        window.clearTimeout(eventTimer);
        eventTimer = null;
    };

    const setActiveSide = (slot) => {
        const normalizedSlot = String(slot || '').toUpperCase();

        Object.entries(scoreNodes).forEach(([scoreSlot, node]) => {
            node?.classList.toggle('is-active', scoreSlot === normalizedSlot);
        });
    };

    const shortTeamName = (value) => {
        const characters = Array.from(String(value || ''));

        return characters.length > 15 ? `${characters.slice(0, 15).join('')}…` : characters.join('');
    };

    const showEvent = (payload = {}) => {
        if (!eventOverlay || !eventLabel) {
            return;
        }

        window.clearTimeout(eventTimer);

        setActiveSide(payload.activeSide || payload.slot || payload.side || payload.gameSideSlot);

        eventTeam.textContent = shortTeamName(payload.teamName);
        eventTeam.dataset.tooltip = payload.teamName || '';
        eventTeam.classList.toggle('ui-tooltip-source', Boolean(payload.teamName));
        eventTeam.classList.toggle('ui-tooltip-source--title', Boolean(payload.teamName));
        eventLabel.textContent = payload.label || payload.eventLabel || 'Событие';
        eventPlayer.textContent = payload.playerName || '';

        if (payload.teamLogo) {
            eventLogo.src = payload.teamLogo;
            eventLogo.alt = payload.teamName ? `Логотип ${payload.teamName}` : '';
            eventLogo.hidden = false;
            eventLogoFallback.hidden = true;
        } else {
            eventLogo.removeAttribute('src');
            eventLogo.alt = '';
            eventLogo.hidden = true;
            eventLogoFallback.hidden = false;
        }

        eventOverlay.hidden = false;
        eventTimer = window.setTimeout(hideEvent, Number(payload.duration ?? 5000));
    };

    const updateScore = (scores = {}) => {
        ['A', 'B'].forEach((slot) => {
            const node = scoreNodes[slot];

            if (node && scores[slot] !== undefined) {
                node.textContent = String(scores[slot]);
            }
        });

        setActiveSide(scores.activeSide || scores.slot || scores.side || scores.gameSideSlot);
    };

    window.addEventListener('mskba:game-live-event', (event) => showEvent(event.detail));
    window.addEventListener('mskba:game-live-score', (event) => updateScore(event.detail));

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }

        if (eventOverlay && !eventOverlay.hidden) {
            hideEvent();
            return;
        }

        if (statsPanel && !statsPanel.hidden) {
            setStatsOpen(false);
        }
    });

    window.MSKBAGameLive = {
        showEvent,
        hideEvent,
        updateScore,
        setActiveSide,
        openStatistics: () => setStatsOpen(true),
        closeStatistics: () => setStatsOpen(false),
    };
}

const gameControl = document.querySelector('[data-game-control]');

if (gameControl) {
    const chips = gameControl.querySelector('.game-control__chips');
    const statusChip = chips?.querySelector('.is-live, .is-complete, .is-expired, .is-planned');

    if (chips && statusChip && !chips.querySelector('.game-live-entry')) {
        const liveUrl = gameControl.dataset.gameLiveUrl;
        if (!liveUrl) {
            return;
        }
        const link = document.createElement('a');
        const isLive = statusChip.classList.contains('is-live');

        link.href = liveUrl;
        link.className = `game-live-entry${isLive ? ' is-live' : ''}`;
        link.innerHTML = isLive
            ? '<span class="game-live-entry__pulse" aria-hidden="true"></span><span>LIVE</span>'
            : '<i class="ti ti-broadcast" aria-hidden="true"></i><span>Live</span>';

        if (isLive) {
            statusChip.replaceWith(link);
        } else {
            statusChip.insertAdjacentElement('afterend', link);
        }
    }
}
