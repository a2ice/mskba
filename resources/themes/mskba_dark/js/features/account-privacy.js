document.querySelectorAll('[data-privacy-rule]').forEach(initPrivacyRule);

function initPrivacyRule(rule) {
    const visibility = rule.querySelector('[data-privacy-visibility]');
    const usersSection = rule.querySelector('[data-privacy-users]');
    const input = rule.querySelector('[data-privacy-user-search]');
    const control = rule.querySelector('[data-privacy-user-control]');
    const results = rule.querySelector('[data-privacy-user-results]');
    const selected = rule.querySelector('[data-privacy-selected]');
    const message = rule.querySelector('[data-privacy-user-message]');
    const searchUrl = rule.dataset.userSearchUrl;
    let timer = null;
    let controller = null;

    if (!visibility || !usersSection || !input || !control || !results || !selected || !message || !searchUrl) {
        return;
    }

    const inputName = visibility.name.replace('[visibility]', '[allowed_user_ids][]');

    visibility.addEventListener('change', updateVisibility);
    updateVisibility();

    input.addEventListener('input', () => {
        window.clearTimeout(timer);
        controller?.abort();
        hideResults();

        const query = input.value.trim();
        if (query.length < 2) {
            message.textContent = 'Введите не менее двух символов.';
            message.classList.remove('text-danger');
            updateControl();
            return;
        }

        message.textContent = '';
        message.classList.remove('text-danger');
        setControlState('loading');
        timer = window.setTimeout(() => search(query), 300);
    });

    input.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown') {
            const firstOption = results.querySelector('[data-privacy-user-option]');
            if (firstOption) {
                event.preventDefault();
                firstOption.focus();
            }
        }

        if (event.key === 'Escape') {
            hideResults();
        }
    });

    results.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            hideResults();
            input.focus();
        }
    });

    control.addEventListener('click', resetSearch);

    results.addEventListener('click', (event) => {
        const option = event.target.closest('[data-privacy-user-option]');
        if (!option) {
            return;
        }

        const users = JSON.parse(results.dataset.users || '[]');
        const user = users[Number(option.dataset.privacyUserOption)];
        if (user) {
            addUser(user);
        }
    });

    selected.addEventListener('click', (event) => {
        const removeButton = event.target.closest('[data-privacy-user-remove]');
        if (removeButton) {
            removeButton.closest('[data-privacy-user-id]')?.remove();
        }
    });

    document.addEventListener('click', (event) => {
        if (!rule.contains(event.target)) {
            hideResults();
        }
    });

    function updateVisibility() {
        usersSection.hidden = visibility.value !== 'selected_users';
        if (usersSection.hidden) {
            hideResults();
        }
    }

    async function search(query) {
        controller?.abort();
        const requestController = new AbortController();
        controller = requestController;

        try {
            const url = new URL(searchUrl, window.location.origin);
            url.searchParams.set('query', query);

            const response = await fetch(url, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: requestController.signal,
            });
            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(payload.message || 'Не удалось найти пользователей.');
            }

            renderResults(Array.isArray(payload.users) ? payload.users : []);
        } catch (error) {
            if (error.name !== 'AbortError') {
                message.textContent = error.message || 'Не удалось найти пользователей.';
                message.classList.add('text-danger');
                hideResults();
            }
        } finally {
            if (controller === requestController) {
                controller = null;
                updateControl();
            }
        }
    }

    function renderResults(users) {
        const selectedIds = new Set(
            [...selected.querySelectorAll('[data-privacy-user-id]')]
                .map((chip) => Number(chip.dataset.privacyUserId)),
        );
        const availableUsers = users.filter((user) => !selectedIds.has(Number(user.id)));

        results.replaceChildren();
        results.dataset.users = JSON.stringify(availableUsers);

        availableUsers.forEach((user, index) => {
            const option = document.createElement('button');
            const name = document.createElement('strong');
            const username = document.createElement('span');

            option.type = 'button';
            option.className = 'predictive-search__item';
            option.dataset.privacyUserOption = String(index);
            option.setAttribute('role', 'option');
            name.className = 'predictive-search__label';
            name.textContent = user.name;
            option.append(name);

            if (user.username) {
                username.className = 'predictive-search__meta';
                username.textContent = `@${user.username}`;
                option.append(username);
            }

            results.append(option);
        });

        if (availableUsers.length === 0) {
            message.textContent = users.length === 0
                ? 'Варианты не найдены.'
                : 'Все найденные пользователи уже выбраны.';
            message.classList.add('text-danger');
            hideResults();
            return;
        }

        message.textContent = '';
        message.classList.remove('text-danger');
        results.classList.remove('d-none');
    }

    function addUser(user) {
        const chip = document.createElement('span');
        const name = document.createElement('span');
        const hiddenInput = document.createElement('input');
        const removeButton = document.createElement('button');

        chip.className = 'account-privacy__chip';
        chip.dataset.privacyUserId = String(user.id);
        name.textContent = user.name;
        chip.append(name);

        if (user.username) {
            const username = document.createElement('small');
            username.textContent = `@${user.username}`;
            chip.append(username);
        }

        hiddenInput.type = 'hidden';
        hiddenInput.name = inputName;
        hiddenInput.value = String(user.id);
        chip.append(hiddenInput);

        removeButton.type = 'button';
        removeButton.dataset.privacyUserRemove = '';
        removeButton.setAttribute('aria-label', `Убрать ${user.name}`);
        removeButton.textContent = '×';
        chip.append(removeButton);

        selected.append(chip);
        input.value = '';
        message.textContent = 'Введите не менее двух символов.';
        message.classList.remove('text-danger');
        hideResults();
        updateControl();
        input.focus();
    }

    function resetSearch() {
        window.clearTimeout(timer);
        controller?.abort();
        controller = null;
        input.value = '';
        message.textContent = 'Введите не менее двух символов.';
        message.classList.remove('text-danger');
        hideResults();
        updateControl();
        input.focus();
    }

    function setControlState(state) {
        control.hidden = state === 'hidden';
        control.disabled = state === 'loading';
        control.classList.toggle('is-loading', state === 'loading');
        control.setAttribute(
            'aria-label',
            state === 'loading' ? 'Идёт поиск пользователей' : 'Очистить поиск',
        );
        input.setAttribute('aria-busy', String(state === 'loading'));
    }

    function updateControl() {
        setControlState(input.value ? 'clear' : 'hidden');
    }

    function hideResults() {
        results.classList.add('d-none');
    }
}
