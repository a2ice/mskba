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
    const telegramLaunch = root.querySelector('[data-telegram-launch]');
    const authUrl = root.dataset.telegramAuthUrl;
    const telegram = window.Telegram?.WebApp;

    bindTelegramMenu(root);

    if (!authUrl) {
        setStatus('Не настроен endpoint авторизации Telegram.');
        return;
    }

    if (!telegram?.initData) {
        setStatus('Откройте эту страницу из Telegram, чтобы авторизоваться через Mini App.');
        return;
    }

    safeTelegramCall(() => telegram.ready());
    safeTelegramCall(() => telegram.expand());

    setStatus('Отправляем Telegram-подпись на сервер...');

    postTelegramAuth(authUrl, {
        init_data: telegram.initData,
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
            telegramLaunch.textContent = [
                payload.telegram_user?.start_param ? `start_param=${payload.telegram_user.start_param}` : null,
                payload.telegram_user?.chat_type ? `chat_type=${payload.telegram_user.chat_type}` : null,
            ].filter(Boolean).join(' · ') || '—';
            summary.hidden = false;
            setStatus(payload.created ? 'Аккаунт создан и авторизован.' : 'Вы авторизованы.');
        })
        .catch((error) => {
            setStatus(readableError(error));
        });

    function setStatus(message) {
        if (status) {
            status.textContent = message;
        }
    }

    function safeTelegramCall(callback) {
        try {
            callback();
        } catch (error) {
            console.debug('Telegram WebApp call skipped:', error);
        }
    }

    function postTelegramAuth(url, payload) {
        return new Promise((resolve, reject) => {
            const request = new XMLHttpRequest();

            request.open('POST', url, true);
            request.withCredentials = true;
            request.setRequestHeader('Accept', 'application/json');
            request.setRequestHeader('Content-Type', 'application/json');
            request.setRequestHeader('X-CSRF-TOKEN', document.querySelector('meta[name="csrf-token"]')?.content || '');

            request.onload = () => {
                const response = parseJson(request.responseText);

                if (request.status >= 200 && request.status < 300) {
                    resolve(response);
                    return;
                }

                reject(new Error(response?.message || `Telegram auth failed: HTTP ${request.status}`));
            };

            request.onerror = () => reject(new Error('Telegram WebView не смог отправить запрос авторизации.'));
            request.ontimeout = () => reject(new Error('Истекло время ожидания авторизации Telegram.'));
            request.timeout = 15000;
            request.send(JSON.stringify(payload));
        });
    }

    function parseJson(value) {
        try {
            return JSON.parse(value);
        } catch (error) {
            return null;
        }
    }

    function readableError(error) {
        const message = error?.message || 'Не удалось авторизоваться через Telegram.';

        if (message === 'The string did not match the expected pattern.') {
            return 'Telegram WebView не смог выполнить запрос авторизации. Обновите Telegram или попробуйте открыть Mini App еще раз.';
        }

        return message;
    }

    function bindTelegramMenu(container) {
        const toggle = container.querySelector('[data-telegram-menu-toggle]');
        const menu = container.querySelector('[data-telegram-menu]');

        if (!toggle || !menu) {
            return;
        }

        toggle.addEventListener('click', () => {
            setMenuOpen(toggle.getAttribute('aria-expanded') !== 'true');
        });

        menu.querySelectorAll('a').forEach((link) => {
            link.addEventListener('click', () => setMenuOpen(false));
        });

        document.addEventListener('click', (event) => {
            if (toggle.contains(event.target) || menu.contains(event.target)) {
                return;
            }

            setMenuOpen(false);
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                setMenuOpen(false);
                toggle.focus();
            }
        });

        function setMenuOpen(isOpen) {
            toggle.setAttribute('aria-expanded', String(isOpen));
            menu.hidden = !isOpen;
        }
    }
});
