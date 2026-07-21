document.addEventListener('DOMContentLoaded', () => {
    const root = document.querySelector('[data-telegram-mini-app]');

    if (!root) {
        return;
    }

    const status = root.querySelector('[data-telegram-status]');
    const dashboard = root.querySelector('[data-telegram-dashboard]');
    const authUrl = root.dataset.telegramAuthUrl;

    bindTelegramMenu(root);
    bindFeatureModal(root);
    bindTelegramVenueSearch(root);
    bindTelegramVenueFlow(root);

    if (!authUrl) {
        setStatus('Не настроен endpoint авторизации Telegram.');
        return;
    }

    authenticateWhenReady();

    async function authenticateWhenReady() {
        const initData = await waitForInitData();

        if (!initData) {
            setStatus('Откройте эту страницу из Telegram, чтобы авторизоваться через Mini App.');
            root.dataset.telegramAuthState = 'missing-init-data';
            return;
        }

        const telegram = window.Telegram?.WebApp;

        safeTelegramCall(() => telegram?.ready());
        safeTelegramCall(() => telegram?.expand());
        setStatus('Отправляем Telegram-подпись на сервер...');

        try {
            const payload = await postTelegramAuth(authUrl, { init_data: initData });
            const nickname = payload.telegram_user?.username
                ? `@${payload.telegram_user.username}`
                : payload.telegram_user?.first_name || payload.user?.username || 'игрок';

            setStatus(`Добро пожаловать, ${nickname}!`);
            root.dataset.telegramAuthState = 'authenticated';
            updateMobileProfile(payload);

            if (dashboard) {
                dashboard.hidden = false;
            }
        } catch (error) {
            root.dataset.telegramAuthState = 'error';
            setStatus(readableError(error));
        }
    }

    function waitForInitData() {
        const launchInitData = readInitDataFromLaunchUrl();
        const sdkInitData = window.Telegram?.WebApp?.initData || '';

        if (sdkInitData || launchInitData) {
            return Promise.resolve(sdkInitData || launchInitData);
        }

        return new Promise((resolve) => {
            const deadline = Date.now() + 3000;
            const timer = window.setInterval(() => {
                const value = window.Telegram?.WebApp?.initData || readInitDataFromLaunchUrl();

                if (value || Date.now() >= deadline) {
                    window.clearInterval(timer);
                    resolve(value || '');
                }
            }, 100);
        });
    }

    function readInitDataFromLaunchUrl() {
        const hash = window.location.hash.startsWith('#') ? window.location.hash.slice(1) : window.location.hash;

        return new URLSearchParams(hash).get('tgWebAppData') || '';
    }

    function updateMobileProfile(payload) {
        const profile = root.querySelector('[data-mobile-profile]');

        if (!profile) {
            return;
        }

        const telegramUser = payload.telegram_user || {};
        const fallbackName = telegramUser.first_name || telegramUser.username || payload.user?.username || '';
        const initials = Array.from(fallbackName).slice(0, 2).join('').toLocaleUpperCase('ru-RU');
        const guest = profile.querySelector('[data-profile-guest]');
        const avatar = profile.querySelector('[data-profile-avatar]');
        const initialsElement = profile.querySelector('[data-profile-initials]');

        profile.dataset.authenticated = '1';
        profile.setAttribute('aria-label', 'Открыть профиль');

        if (profile instanceof HTMLAnchorElement && root.dataset.accountUrl) {
            profile.href = root.dataset.accountUrl;
        }

        if (guest) {
            guest.hidden = true;
        }

        if (avatar && telegramUser.photo_url) {
            avatar.src = telegramUser.photo_url;
            avatar.hidden = false;
            avatar.addEventListener('error', () => {
                avatar.hidden = true;

                if (initialsElement) {
                    initialsElement.hidden = false;
                }
            }, { once: true });
        } else if (initialsElement) {
            initialsElement.hidden = false;
        }

        if (initialsElement) {
            initialsElement.textContent = initials;
        }
    }

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

    function bindTelegramVenueFlow(container) {
        const openButton = container.querySelector('[data-telegram-venue-create-open]');
        const createModal = container.querySelector('[data-telegram-venue-create-modal]');
        const editModal = container.querySelector('[data-telegram-venue-edit-modal]');
        const createForm = createModal?.querySelector('[data-telegram-venue-create-form]');
        const editForm = editModal?.querySelector('[data-telegram-venue-edit-form]');
        const moderationForm = editModal?.querySelector('[data-telegram-venue-moderation-form]');
        const moderationStatus = editModal?.querySelector('[data-telegram-venue-moderation-status]');
        const moderationHistory = editModal?.querySelector('[data-telegram-venue-moderation-history]');
        const moderationHistoryList = editModal?.querySelector('[data-telegram-venue-moderation-history-list]');
        let moderationStateUrl = null;

        if (!openButton || !createModal || !editModal || !createForm || !editForm || !moderationForm) {
            return;
        }

        openButton.addEventListener('click', () => openModal(createModal));

        container.querySelectorAll('[data-telegram-venue-modal-close]').forEach((button) => {
            button.addEventListener('click', () => closeModal(button.closest('.telegram-feature-modal')));
        });

        [createForm, editForm].forEach((form) => {
            const metro = form.querySelector('[data-address-metro-select]');
            metro?.addEventListener('change', () => updateMetroSummary(form));
        });

        createForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            const payload = await submitForm(createForm);

            if (!payload) {
                return;
            }

            copyFormValues(createForm, editForm);
            editForm.action = payload.venue.update_url;
            moderationForm.action = payload.venue.moderation_url;
            moderationStateUrl = payload.venue.moderation_state_url;
            updateMetroSummary(editForm, payload.venue.metro);
            closeModal(createModal);
            openModal(editModal);
            showMessage(editForm, payload.message, 'success');
            await refreshModerationState();
        });

        editForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            const payload = await submitForm(editForm);

            if (payload) {
                updateMetroSummary(editForm, payload.venue.metro);
            }

            await refreshModerationState();
        });

        moderationForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            await submitForm(moderationForm);
            await refreshModerationState();
        });

        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') {
                return;
            }

            [createModal, editModal].forEach((modal) => {
                if (!modal.hidden) {
                    closeModal(modal);
                }
            });
        });

        function openModal(modal) {
            modal.hidden = false;
            modal.querySelector('input:not([type="hidden"]), button')?.focus();
        }

        function closeModal(modal) {
            if (modal) {
                modal.hidden = true;
            }
        }

        async function refreshModerationState() {
            if (!moderationStateUrl) {
                return;
            }

            try {
                const response = await fetch(moderationStateUrl, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                const payload = await response.json().catch(() => ({}));

                if (!response.ok || !payload.moderation) {
                    return;
                }

                renderModerationState(payload.moderation);
            } catch (error) {
                // Submit feedback is already visible; state will be retried after the next action.
            }
        }

        function renderModerationState(moderation) {
            const button = moderationForm.querySelector('button[type="submit"]');
            button.disabled = !moderation.can_submit;
            button.textContent = moderation.can_submit ? 'Отправить на модерацию' : moderation.label;
            moderationStatus.textContent = `Статус: ${moderation.label}`;

            const requests = Array.isArray(moderation.history) ? moderation.history : [];
            moderationHistory.hidden = requests.length === 0;
            moderationHistoryList.replaceChildren(...requests.map(renderModerationRequest));
        }

        function renderModerationRequest(request) {
            const article = document.createElement('article');
            const header = document.createElement('header');
            const title = document.createElement('strong');
            const status = document.createElement('span');
            const messages = document.createElement('div');

            article.className = 'telegram-venue-moderation-request';
            header.className = 'telegram-venue-moderation-request__header';
            title.textContent = `Запрос №${request.id} · ${request.submitted_at || 'Дата не указана'}`;
            status.textContent = request.status_label;
            status.className = `telegram-venue-moderation-request__status telegram-venue-moderation-request__status--${request.status}`;
            header.append(title, status);

            const requestMessages = Array.isArray(request.messages) ? request.messages : [];
            if (requestMessages.length === 0) {
                const empty = document.createElement('p');
                empty.textContent = 'Запрос отправлен без комментария.';
                messages.append(empty);
            } else {
                requestMessages.forEach((message) => {
                    const item = document.createElement('div');
                    const meta = document.createElement('small');
                    const text = document.createElement('p');

                    item.className = `telegram-venue-moderation-message telegram-venue-moderation-message--${message.sender_label === 'Вы' ? 'owner' : 'moderator'}`;
                    meta.textContent = `${message.sender_label} (${message.sender_username}) · ${message.created_at || '—'}`;
                    text.textContent = message.message;
                    item.append(meta, text);
                    messages.append(item);
                });
            }

            article.append(header, messages);
            return article;
        }

        async function submitForm(form) {
            clearFormFeedback(form);
            const button = form.querySelector('button[type="submit"]');
            button.disabled = true;

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    },
                    credentials: 'same-origin',
                    body: new FormData(form),
                });
                const payload = await response.json().catch(() => ({}));

                if (!response.ok) {
                    renderErrors(form, payload.errors || {});
                    showMessage(form, payload.message || 'Не удалось выполнить действие.', 'error');
                    return null;
                }

                showMessage(form, payload.message || 'Готово.', 'success');
                return payload;
            } catch (error) {
                showMessage(form, 'Не удалось связаться с сервером. Попробуйте ещё раз.', 'error');
                return null;
            } finally {
                button.disabled = false;
            }
        }

        function clearFormFeedback(form) {
            form.querySelectorAll('[data-field-error]').forEach((element) => {
                element.textContent = '';
            });
            form.querySelectorAll('.is-invalid').forEach((element) => element.classList.remove('is-invalid'));
            showMessage(form, '', '');
        }

        function renderErrors(form, errors) {
            Object.entries(errors).forEach(([field, messages]) => {
                const error = form.querySelector(`[data-field-error="${field}"]`);
                const input = findNamedField(form, field);

                if (error) {
                    error.textContent = Array.isArray(messages) ? messages[0] : messages;
                }
                input?.classList.add('is-invalid');
            });
        }

        function findNamedField(form, dottedName) {
            const bracketName = dottedName.replace(/\.([^.]*)/g, '[$1]');
            return Array.from(form.elements).find((field) => field.name === bracketName);
        }

        function showMessage(form, message, state) {
            const target = form.querySelector('[data-form-message]');

            if (!target) {
                return;
            }

            target.textContent = message;
            target.className = `telegram-venue-form__message${state ? ` telegram-venue-form__message--${state}` : ''}`;
        }

        function copyFormValues(source, target) {
            Array.from(source.elements).forEach((sourceField) => {
                if (!sourceField.name || sourceField.name === '_token') {
                    return;
                }

                const targets = Array.from(target.elements).filter((field) => field.name === sourceField.name);
                targets.forEach((targetField) => {
                    if (targetField instanceof HTMLSelectElement && targetField.multiple) {
                        const selected = Array.from(sourceField.selectedOptions || []).map((option) => option.value);
                        Array.from(targetField.options).forEach((option) => {
                            option.selected = selected.includes(option.value);
                        });
                    } else {
                        targetField.value = sourceField.value;
                    }
                });
            });

            target.querySelector('[data-address-suggest-input]')
                ?.dispatchEvent(new Event('address-suggest:sync'));
        }

        function updateMetroSummary(form, metroPayload = null) {
            const summary = form.querySelector('[data-telegram-venue-metro]');
            const select = form.querySelector('[data-address-metro-select]');
            const labels = Array.isArray(metroPayload)
                ? metroPayload.map((station) => station.label)
                : Array.from(select?.selectedOptions || []).map((option) => option.textContent.trim());

            if (summary) {
                summary.textContent = labels.length ? labels.join(', ') : 'Метро рядом не найдено';
            }
        }
    }

    function bindTelegramVenueSearch(container) {
        const openButton = container.querySelector('[data-telegram-venue-search-open]');
        const modal = container.querySelector('[data-telegram-venue-search-modal]');
        const form = modal?.querySelector('[data-telegram-venue-search-form]');
        const results = modal?.querySelector('[data-telegram-venue-search-results]');
        const message = modal?.querySelector('[data-form-message]');
        let timer = null;

        if (!openButton || !modal || !form || !results || !message) {
            return;
        }

        openButton.addEventListener('click', () => {
            modal.hidden = false;
            form.querySelector('input[type="search"]')?.focus();
            search();
        });

        modal.querySelectorAll('[data-telegram-venue-search-close]').forEach((button) => {
            button.addEventListener('click', close);
        });

        form.addEventListener('submit', (event) => {
            event.preventDefault();
            search();
        });

        form.querySelectorAll('select').forEach((select) => {
            select.addEventListener('change', search);
        });

        form.querySelector('input[type="search"]')?.addEventListener('input', () => {
            clearTimeout(timer);
            timer = setTimeout(search, 350);
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !modal.hidden) {
                close();
            }
        });

        async function search() {
            const submit = form.querySelector('button[type="submit"]');
            const parameters = new URLSearchParams();

            new FormData(form).forEach((value, key) => {
                if (String(value).trim() !== '') {
                    parameters.set(key, String(value));
                }
            });

            if (submit) {
                submit.disabled = true;
            }
            setMessage('Ищем площадки...', '');

            try {
                const response = await fetch(`${form.action}?${parameters.toString()}`, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                const payload = await response.json().catch(() => ({}));

                if (!response.ok) {
                    renderResults([]);
                    setMessage(payload.message || 'Не удалось выполнить поиск.', 'error');
                    return;
                }

                const venues = Array.isArray(payload.venues) ? payload.venues : [];
                renderResults(venues);
                setMessage(venues.length ? `Найдено: ${venues.length}` : 'Площадки не найдены.', venues.length ? 'success' : '');
            } catch (error) {
                renderResults([]);
                setMessage('Не удалось связаться с сервером. Попробуйте ещё раз.', 'error');
            } finally {
                if (submit) {
                    submit.disabled = false;
                }
            }
        }

        function renderResults(venues) {
            results.replaceChildren(...venues.map((venue) => {
                const card = document.createElement('a');
                const headingRow = document.createElement('div');
                const heading = document.createElement('strong');
                const meta = document.createElement('span');
                const description = document.createElement('span');

                card.className = 'telegram-venue-search-card';
                card.href = venue.url;
                headingRow.className = 'telegram-venue-search-card__heading';
                heading.textContent = venue.name;
                meta.textContent = [
                    venue.type,
                    venue.has_free_access ? 'Свободный доступ' : null,
                    venue.requires_payment ? 'Платная' : null,
                    venue.requires_booking_approval ? 'С подтверждением' : null,
                    venue.address,
                ].filter(Boolean).join(' · ');
                description.textContent = venue.description || '';
                headingRow.append(heading);

                if (venue.is_confirmed) {
                    const statusBadge = document.createElement('span');

                    statusBadge.className = 'telegram-venue-search-card__status';
                    statusBadge.textContent = '✓';
                    statusBadge.title = venue.status;
                    statusBadge.setAttribute('aria-label', venue.status);
                    headingRow.append(statusBadge);
                }

                card.append(headingRow, meta);

                if (description.textContent) {
                    card.append(description);
                }

                return card;
            }));
        }

        function setMessage(text, state) {
            message.textContent = text;
            message.className = `telegram-venue-form__message${state ? ` telegram-venue-form__message--${state}` : ''}`;
        }

        function close() {
            modal.hidden = true;
            openButton.focus();
        }
    }
});
