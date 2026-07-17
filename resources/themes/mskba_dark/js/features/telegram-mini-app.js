document.addEventListener('DOMContentLoaded', () => {
    const root = document.querySelector('[data-telegram-mini-app]');

    if (!root) {
        return;
    }

    const status = root.querySelector('[data-telegram-status]');
    const dashboard = root.querySelector('[data-telegram-dashboard]');
    const authUrl = root.dataset.telegramAuthUrl;
    const telegram = window.Telegram?.WebApp;

    bindTelegramMenu(root);
    bindFeatureModal(root);

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
            const nickname = payload.telegram_user?.username
                ? `@${payload.telegram_user.username}`
                : payload.telegram_user?.first_name || payload.user?.username || 'игрок';

            setStatus(`Добро пожаловать, ${nickname}!`);

            if (dashboard) {
                dashboard.hidden = false;
            }
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

    function bindFeatureModal(container) {
        const modal = container.querySelector('[data-telegram-feature-modal]');
        const title = modal?.querySelector('[data-telegram-feature-title]');
        const openButtons = container.querySelectorAll('[data-telegram-feature-open]');
        const closeButtons = modal?.querySelectorAll('[data-telegram-feature-close]') || [];
        let trigger = null;

        if (!modal || !title) {
            return;
        }

        openButtons.forEach((button) => {
            button.addEventListener('click', () => {
                trigger = button;
                title.textContent = button.dataset.featureTitle || 'Новый раздел';
                modal.hidden = false;
                modal.querySelector('.telegram-feature-modal__close')?.focus();
            });
        });

        closeButtons.forEach((button) => {
            button.addEventListener('click', closeModal);
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !modal.hidden) {
                closeModal();
            }
        });

        function closeModal() {
            modal.hidden = true;
            trigger?.focus();
            trigger = null;
        }
    }
});
