import QRCode from 'qrcode';

function initUsernameCheck() {
    const root = document.querySelector('[data-tournament-check-in][data-username-url]');
    const input = root?.querySelector('[data-check-in-username]');
    const message = root?.querySelector('[data-check-in-username-message]');
    const submit = root?.querySelector('[data-check-in-submit]');
    if (!root || !input || !message || !submit) return;

    let timer = null;
    let controller = null;
    const setState = (available, text) => {
        input.classList.toggle('is-valid', available === true);
        input.classList.toggle('is-invalid', available === false);
        message.textContent = text;
        submit.disabled = available !== true;
    };
    input.addEventListener('input', () => {
        window.clearTimeout(timer);
        controller?.abort();
        const username = input.value.trim();
        if (username.length < 3) {
            setState(null, 'Введите не менее трёх символов.');
            return;
        }
        setState(null, 'Проверяем логин…');
        timer = window.setTimeout(async () => {
            controller = new AbortController();
            try {
                const response = await fetch(`${root.dataset.usernameUrl}?username=${encodeURIComponent(username)}`, {headers: {'Accept': 'application/json'}, signal: controller.signal});
                const payload = await response.json();
                setState(response.ok && payload.available === true, payload.message || 'Не удалось проверить логин.');
            } catch (error) {
                if (error.name !== 'AbortError') setState(false, 'Не удалось проверить логин. Попробуйте ещё раз.');
            }
        }, 350);
    });
}

function initRoleRestore() {
    const root = document.querySelector('[data-tournament-check-in]');
    if (!root) return;

    const storageKey = `tournament-check-in-roles:${window.location.pathname}`;
    const roleInputs = [...root.querySelectorAll('input[name="roles[]"]')];
    root.querySelector('[data-check-in-auth]')?.addEventListener('click', () => {
        window.sessionStorage.setItem(storageKey, JSON.stringify(roleInputs.filter((input) => input.checked).map((input) => input.value)));
    });

    try {
        const roles = JSON.parse(window.sessionStorage.getItem(storageKey) || '[]');
        if (Array.isArray(roles)) roleInputs.forEach((input) => { input.checked = roles.includes(input.value); });
        window.sessionStorage.removeItem(storageKey);
    } catch {
        window.sessionStorage.removeItem(storageKey);
    }
}

function initCheckInShare() {
    document.querySelectorAll('[data-tournament-check-in-share]').forEach(async (root) => {
        const url = root.dataset.checkInUrl;
        const qrContainer = root.querySelector('[data-check-in-qr]');
        if (!url || !qrContainer) return;

        const canvas = document.createElement('canvas');
        canvas.setAttribute('aria-hidden', 'true');
        qrContainer.append(canvas);
        await QRCode.toCanvas(canvas, url, {width: 160, margin: 1, color: {dark: '#111511', light: '#ffffff'}});

        root.querySelector('[data-check-in-copy]')?.addEventListener('click', async () => {
            const status = root.querySelector('[data-check-in-copy-status]');
            try {
                await navigator.clipboard.writeText(url);
                if (status) status.textContent = 'Ссылка скопирована.';
            } catch {
                root.querySelector('[data-check-in-link]')?.select();
                if (status) status.textContent = 'Выделили ссылку — скопируйте её вручную.';
            }
        });
    });
}

function initDecisionRefresh() {
    const root = document.querySelector('[data-tournament-check-in][data-pending-admission-id]');
    if (!root) return;

    document.addEventListener('notification:created', (event) => {
        const context = event.detail?.context;
        if (!['tournament.on_site.accepted', 'tournament.on_site.declined'].includes(context?.source)) return;
        if (String(context.tournament_admission_id) !== root.dataset.pendingAdmissionId) return;

        window.setTimeout(() => window.location.reload(), 150);
    });
}

document.addEventListener('DOMContentLoaded', () => {
    initUsernameCheck();
    initRoleRestore();
    initCheckInShare();
    initDecisionRefresh();
});
