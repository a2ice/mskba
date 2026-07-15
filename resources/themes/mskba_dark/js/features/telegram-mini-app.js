document.addEventListener('DOMContentLoaded', () => {
    const root = document.querySelector('[data-telegram-mini-app]');

    if (!root) {
        return;
    }

    const status = root.querySelector('[data-telegram-status]');
    const summary = root.querySelector('[data-telegram-summary]');
    const telegramName = root.querySelector('[data-telegram-name]');
    const mskbaUser = root.querySelector('[data-mskba-user]');
    const registrationChannel = root.querySelector('[data-registration-channel]');
    const telegram = window.Telegram?.WebApp;

    if (!telegram?.initData) {
        setStatus('Откройте эту страницу из Telegram, чтобы авторизоваться через Mini App.');
        return;
    }

    telegram.ready();
    telegram.expand();

    fetch('/integrations/telegram/auth', {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
        },
        body: JSON.stringify({
            init_data: telegram.initData,
        }),
    })
        .then(async (response) => {
            const payload = await response.json();

            if (!response.ok) {
                throw new Error(payload.message || 'Не удалось авторизоваться через Telegram.');
            }

            return payload;
        })
        .then((payload) => {
            const name = [
                payload.telegram_user?.first_name,
                payload.telegram_user?.last_name,
            ].filter(Boolean).join(' ');
            const username = payload.telegram_user?.username
                ? `@${payload.telegram_user.username}`
                : '';

            telegramName.textContent = [name || 'Без имени', username].filter(Boolean).join(' · ');
            mskbaUser.textContent = `#${payload.user.id} · ${payload.user.username || 'без логина'}`;
            registrationChannel.textContent = payload.user.registration_channel || '—';
            summary.hidden = false;
            setStatus(payload.created ? 'Аккаунт создан и авторизован.' : 'Вы авторизованы.');
        })
        .catch((error) => {
            setStatus(error.message);
        });

    function setStatus(message) {
        if (status) {
            status.textContent = message;
        }
    }
});
