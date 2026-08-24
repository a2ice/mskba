import QRCode from 'qrcode';

const createShareCard = (joinUrl) => {
    const card = document.createElement('section');
    card.className = 'game-qr-share';
    card.dataset.gameQrShare = '';

    card.innerHTML = `
        <div class="game-qr-share__copy">
            <span class="eyebrow">Быстрый сбор игроков</span>
            <h3>QR для подключения к игре</h3>
            <p>Покажите код игрокам на площадке. После сканирования они смогут войти в свой аккаунт и подать заявку в balanced-пул.</p>
            <div class="game-qr-share__actions">
                <button class="btn btn--secondary btn--sm" type="button" data-game-qr-copy>Скопировать ссылку</button>
                <a class="btn btn--primary btn--sm" href="${joinUrl}">Открыть страницу игрока</a>
            </div>
            <small class="game-qr-share__status" data-game-qr-copy-status aria-live="polite"></small>
        </div>
        <div class="game-qr-share__code" data-game-qr-code aria-label="QR-код для подключения к игре"></div>
    `;

    return card;
};

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

const initGameQrShare = () => {
    const gameRoot = document.querySelector('[data-game-control]');
    if (!(gameRoot instanceof HTMLElement)) return;

    const organizerView = gameRoot.hasAttribute('data-game-lifecycle-url')
        || gameRoot.querySelector('.event-game-management-link') !== null;
    if (!organizerView) return;

    const liveUrl = gameRoot.dataset.gameLiveUrl;
    if (!liveUrl) return;

    const gameUrl = new URL(liveUrl, window.location.origin);
    gameUrl.pathname = gameUrl.pathname.replace(/\/live\/?$/, '');
    const recruitmentBase = `${gameUrl.pathname}/recruitment`;
    const joinUrl = `${window.location.origin}${recruitmentBase}/join`;

    const enhancePanel = () => {
        const panel = gameRoot.querySelector('[data-standalone-recruitment-panel]');
        if (!(panel instanceof HTMLElement) || panel.querySelector('[data-game-qr-share]')) return;

        const balancedFormation = panel.querySelector('[data-balanced-formation]');
        const publicApplyForm = panel.querySelector('form[action$="/apply"]');
        const individualPublicApplication = publicApplyForm instanceof HTMLFormElement
            && publicApplyForm.querySelector('[name="team_id"]') === null;

        if (!balancedFormation && !individualPublicApplication) return;

        const applicationsToggle = panel.querySelector('form[action$="/applications"] input[name="enabled"][value="1"]');
        if (applicationsToggle instanceof HTMLInputElement && !applicationsToggle.checked) return;

        const card = createShareCard(joinUrl);
        const heading = panel.firstElementChild;
        if (heading) heading.after(card);
        else panel.prepend(card);

        const qrContainer = card.querySelector('[data-game-qr-code]');
        if (qrContainer instanceof HTMLElement) {
            renderQr(qrContainer, joinUrl).catch(() => {
                qrContainer.textContent = 'Не удалось построить QR-код.';
            });
        }

        card.querySelector('[data-game-qr-copy]')?.addEventListener('click', async () => {
            const status = card.querySelector('[data-game-qr-copy-status]');
            try {
                await navigator.clipboard.writeText(joinUrl);
                if (status) status.textContent = 'Ссылка скопирована.';
            } catch {
                if (status) status.textContent = joinUrl;
            }
        });
    };

    enhancePanel();
    const observer = new MutationObserver(enhancePanel);
    observer.observe(gameRoot, { childList: true, subtree: true });
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
    initGameQrShare();
    initJoinDecisionRefresh();
});
