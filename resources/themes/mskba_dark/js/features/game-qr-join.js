import QRCode from 'qrcode';

const renderQr = async (container, joinUrl) => {
    const canvas = document.createElement('canvas');
    canvas.setAttribute('aria-hidden', 'true');
    container.replaceChildren(canvas);

    await QRCode.toCanvas(canvas, joinUrl, {
        width: 220,
        margin: 1,
        color: { dark: '#111511', light: '#ffffff' },
    });
};

const bindShare = (root) => {
    const joinUrl = root.dataset.gameQrJoinUrl;
    if (!joinUrl) return;

    const qrContainer = root.querySelector('[data-game-qr-code]');
    if (qrContainer instanceof HTMLElement) {
        renderQr(qrContainer, joinUrl).catch(() => {
            qrContainer.textContent = 'Не удалось построить QR-код.';
        });
    }

    root.querySelector('[data-game-qr-copy]')?.addEventListener('click', async () => {
        const status = root.querySelector('[data-game-qr-copy-status]');
        try {
            await navigator.clipboard.writeText(joinUrl);
            if (status) status.textContent = 'Ссылка скопирована.';
        } catch {
            if (status) status.textContent = joinUrl;
        }
    });
};

const bindLateActions = (root) => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    root.querySelectorAll('[data-game-late-join-ajax]').forEach((form) => {
        if (!(form instanceof HTMLFormElement)) return;

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const buttons = [...form.querySelectorAll('button, input[type="submit"]')];
            buttons.forEach((button) => { button.disabled = true; });

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
                    body: new FormData(form),
                });
                const payload = await response.json().catch(() => ({}));
                if (!response.ok) throw new Error(payload.message || 'Не удалось выполнить действие.');
                window.location.reload();
            } catch (error) {
                window.alert(error.message || 'Не удалось выполнить действие.');
                buttons.forEach((button) => { button.disabled = false; });
            }
        });
    });
};

const initOrganizerLateJoinPanel = () => {
    const gameRoot = document.querySelector('[data-game-control]');
    if (!(gameRoot instanceof HTMLElement)) return;

    const organizerView = gameRoot.hasAttribute('data-game-lifecycle-url')
        || gameRoot.querySelector('.event-game-management-link') !== null;
    if (!organizerView) return;

    const liveUrl = gameRoot.dataset.gameLiveUrl;
    if (!liveUrl) return;

    const gameUrl = new URL(liveUrl, window.location.origin);
    gameUrl.pathname = gameUrl.pathname.replace(/\/live\/?$/, '');
    const latePanelUrl = `${gameUrl.pathname}/recruitment/late-panel`;

    fetch(latePanelUrl, { headers: { Accept: 'text/html' } })
        .then((response) => response.ok ? response.text() : '')
        .then((html) => {
            if (!html.trim()) return;
            const template = document.createElement('template');
            template.innerHTML = html.trim();
            const panel = template.content.firstElementChild;
            if (!(panel instanceof HTMLElement)) return;

            const scoreboard = gameRoot.querySelector('[data-game-scoreboard]');
            if (scoreboard) scoreboard.before(panel);
            else gameRoot.querySelector('.game-control__header')?.after(panel);

            bindShare(panel);
            bindLateActions(panel);
        })
        .catch(() => {
            // QR join management is supplemental; the main game page must remain usable.
        });
};

const initJoinDecisionRefresh = () => {
    const root = document.querySelector('[data-game-qr-join][data-pending-admission-id]');
    if (!(root instanceof HTMLElement)) return;

    document.addEventListener('notification:created', (event) => {
        const context = event.detail?.context;
        if (context?.source !== 'game.recruitment') return;
        if (String(context.game_id ?? '') !== root.dataset.gameId) return;

        window.setTimeout(() => window.location.reload(), 150);
    });
};

document.addEventListener('DOMContentLoaded', () => {
    initOrganizerLateJoinPanel();
    initJoinDecisionRefresh();
});
