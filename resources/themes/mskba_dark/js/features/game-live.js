import { realtimeState, subscribePublic } from '../../../../js/realtime.js';

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
    let revision = null;
    let latestActionSequence = null;
    let snapshotRequest = null;

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

    const renderPlayer = (player) => {
        const node = statsPanel?.querySelector(`[data-game-live-player="${player.user_id}"]`);
        if (!node) {
            return;
        }

        const points = node.querySelector('[data-game-live-player-points]');
        if (points) {
            points.replaceChildren(document.createTextNode(String(player.points)), document.createTextNode(' '));
            const unit = document.createElement('small');
            unit.textContent = 'очк.';
            points.append(unit);
        }

        const list = node.querySelector('dl');
        if (!list) {
            return;
        }

        list.replaceChildren(...player.statistics.map((statistic) => {
            const item = document.createElement('div');
            const term = document.createElement('dt');
            const value = document.createElement('dd');
            term.textContent = statistic.label;
            value.textContent = String(statistic.value);
            value.dataset.gameLiveStat = statistic.field;
            item.append(term, value);
            return item;
        }));
    };

    const renderPeriods = (timing, teams, labels) => {
        const root = statsPanel?.querySelector('[data-game-live-periods]');
        if (!root) {
            return;
        }

        const names = Object.fromEntries(
            Object.values(teams).flatMap((team) => team.players.map((player) => [player.user_id, player.name])),
        );
        const heading = document.createElement('h3');
        heading.textContent = 'По периодам';

        root.replaceChildren(heading, ...timing.periods.map((period) => {
            const details = document.createElement('details');
            const summary = document.createElement('summary');
            summary.textContent = `Период ${period.number} · ${period.score_a ?? 0}:${period.score_b ?? 0}`;
            details.append(summary);
            const players = Object.entries(period.players || {});
            if (!players.length) {
                const empty = document.createElement('p');
                empty.textContent = 'Действий пока нет.';
                details.append(empty);
            } else {
                players.forEach(([userId, values]) => {
                    const line = document.createElement('p');
                    const name = document.createElement('strong');
                    name.textContent = names[userId] || `Игрок #${userId}`;
                    line.append(name, document.createTextNode(`: ${Object.entries(values).map(([field, value]) => `${labels[field] || field} ${value}`).join(', ')}`));
                    details.append(line);
                });
            }
            return details;
        }));
    };

    const applySnapshot = (snapshot, announceAction = true) => {
        if (!snapshot || snapshot.revision === revision) {
            return;
        }

        updateScore({ ...snapshot.scores, activeSide: snapshot.active_side });
        Object.values(snapshot.teams).forEach((team) => team.players.forEach(renderPlayer));
        renderPeriods(snapshot.timing, snapshot.teams, snapshot.statistics_labels || {});

        const periodNode = liveScreen.querySelector('[data-game-live-active-period]');
        if (periodNode && snapshot.timing.active_period) {
            periodNode.textContent = `ПЕРИОД ${snapshot.timing.active_period} ИЗ ${snapshot.timing.periods_count}`;
            periodNode.dataset.gameLiveActivePeriod = snapshot.timing.active_period;
            periodNode.hidden = false;
        } else if (periodNode) {
            periodNode.hidden = true;
        }

        const statusRoot = liveScreen.querySelector('.game-live-header__status');
        const statusNode = liveScreen.querySelector('[data-game-live-status]');
        const pulse = statusRoot?.querySelector('.game-live-pulse');
        const isCancelled = snapshot.status.value === 'cancelled';
        const statusLabel = snapshot.status.is_live ? 'LIVE' : (snapshot.status.is_finished ? 'ЗАВЕРШЕНА' : (isCancelled ? 'ОТМЕНЕНА' : 'ТРАНСЛЯЦИЯ'));
        if (statusNode) statusNode.textContent = statusLabel;
        statusRoot?.classList.toggle('is-live', snapshot.status.is_live);
        if (pulse) pulse.hidden = !snapshot.status.is_live;

        const action = snapshot.latest_action;
        if (announceAction && action && latestActionSequence !== null && action.sequence > latestActionSequence) {
            showEvent({
                activeSide: action.slot,
                label: action.label,
                playerName: action.player_name,
                teamName: action.team_name,
                teamLogo: action.team_logo,
            });
        }

        latestActionSequence = action?.sequence ?? latestActionSequence;
        revision = snapshot.revision;
    };

    const refreshSnapshot = async (announceAction = true) => {
        if (!liveScreen.dataset.gameLiveSnapshotUrl || snapshotRequest) {
            return snapshotRequest;
        }

        snapshotRequest = fetch(liveScreen.dataset.gameLiveSnapshotUrl, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(`Snapshot request failed: ${response.status}`);
                }
                return response.json();
            })
            .then((snapshot) => applySnapshot(snapshot, announceAction))
            .catch(() => undefined)
            .finally(() => { snapshotRequest = null; });

        return snapshotRequest;
    };

    refreshSnapshot(false);
    const unsubscribe = liveScreen.dataset.gameLiveChannel
        ? subscribePublic(liveScreen.dataset.gameLiveChannel, '.game.live.updated', () => refreshSnapshot(true))
        : () => {};
    const onRealtimeState = (event) => {
        if (event.detail.state === 'connected') {
            refreshSnapshot(true);
        }
    };
    window.addEventListener('mskba:realtime-state', onRealtimeState);
    const fallbackTimer = window.setInterval(() => {
        if (realtimeState() !== 'connected') {
            refreshSnapshot(true);
        }
    }, 10000);
    window.addEventListener('pagehide', () => {
        window.clearInterval(fallbackTimer);
        window.removeEventListener('mskba:realtime-state', onRealtimeState);
        unsubscribe();
    }, { once: true });

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
        if (liveUrl) {
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
}
