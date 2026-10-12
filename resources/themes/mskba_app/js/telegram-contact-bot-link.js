// Telegram bot contact linking for authenticated users.
// No Telegram widget, no client-provided Telegram identity and no login/session replacement.
export function createTelegramContactBotLink({ root, dialog, post, onSuccess, onError }) {
    const anchor = dialog.querySelector('[data-bot-link]');
    const statusNode = dialog.querySelector('[data-bot-status]');
    let generation = 0;
    let timer = null;

    function stop() {
        generation += 1;
        if (timer !== null) clearTimeout(timer);
        timer = null;
    }

    async function start() {
        stop();
        const current = generation;
        anchor.hidden = true;
        anchor.removeAttribute('href');
        statusNode.textContent = 'Создаём персональную ссылку…';

        try {
            const result = await post(root.dataset.telegramStartUrl, {});
            if (current !== generation || !dialog.open) return;
            anchor.href = result.bot_url;
            anchor.hidden = false;
            statusNode.textContent = 'Открой бота, нажми «Начать» и подтверди привязку. Оставь эту страницу открытой.';

            async function poll() {
                if (current !== generation || !dialog.open) return;
                try {
                    const status = await post(root.dataset.telegramStatusUrl, { token: result.token });
                    if (current !== generation || !dialog.open) return;
                    if (status.status === 'success') {
                        stop();
                        statusNode.textContent = 'Telegram подтверждён.';
                        await onSuccess(status);
                        return;
                    }
                    statusNode.textContent = status.message || 'Ожидаем подтверждения в Telegram…';
                } catch (error) {
                    if (current !== generation || !dialog.open) return;
                    if (error.status === 409 && error.payload?.redirect_url) {
                        stop();
                        window.location.assign(error.payload.redirect_url);
                        return;
                    }
                    if (error.status === 410 || error.status === 422 || error.status === 403) {
                        stop();
                        statusNode.textContent = '';
                        onError(error.message);
                        return;
                    }
                    statusNode.textContent = 'Проверяем подтверждение…';
                }
                if (current === generation) timer = setTimeout(poll, 3000);
            }

            timer = setTimeout(poll, 3000);
        } catch (error) {
            if (current !== generation) return;
            statusNode.textContent = '';
            onError(error.message || 'Не удалось открыть Telegram-бот.');
        }
    }

    return { start, stop };
}
