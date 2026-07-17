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
    bindTelegramVenueSearch(root);
    bindTelegramVenueFlow(root);

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

    function bindTelegramVenueFlow(container) {
        const openButton = container.querySelector('[data-telegram-venue-create-open]');
        const createModal = container.querySelector('[data-telegram-venue-create-modal]');
        const editModal = container.querySelector('[data-telegram-venue-edit-modal]');
        const createForm = createModal?.querySelector('[data-telegram-venue-create-form]');
        const editForm = editModal?.querySelector('[data-telegram-venue-edit-form]');
        const moderationForm = editModal?.querySelector('[data-telegram-venue-moderation-form]');

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
            updateMetroSummary(editForm, payload.venue.metro);
            closeModal(createModal);
            openModal(editModal);
            showMessage(editForm, payload.message, 'success');
        });

        editForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            const payload = await submitForm(editForm);

            if (payload) {
                updateMetroSummary(editForm, payload.venue.metro);
            }
        });

        moderationForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            const payload = await submitForm(moderationForm);

            if (payload) {
                moderationForm.querySelector('button[type="submit"]').disabled = true;
            }
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

            submit.disabled = true;
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
                submit.disabled = false;
            }
        }

        function renderResults(venues) {
            results.replaceChildren(...venues.map((venue) => {
                const card = document.createElement('a');
                const heading = document.createElement('strong');
                const meta = document.createElement('span');
                const description = document.createElement('span');

                card.className = 'telegram-venue-search-card';
                card.href = venue.url;
                heading.textContent = venue.name;
                meta.textContent = [venue.type, venue.address].filter(Boolean).join(' · ');
                description.textContent = venue.description || '';
                card.append(heading, meta);

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
