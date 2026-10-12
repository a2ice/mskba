// Account confirmation profile editor: reuse the established PATCH /account/profile.
// Client cooldown complements backend validation and never marks unsaved values as saved.
const form = document.querySelector('[data-confirmation-profile]');

if (form) {
    const card = form.querySelector('.app-confirmation__card');
    const allButton = form.querySelector('[data-confirmation-save-all]');
    const submit = document.querySelector('[data-confirmation-submit]');
    const requirement = document.querySelector('[data-confirmation-requirement]');
    const status = form.querySelector('[data-confirmation-profile-state]');
    const feedback = form.querySelector('[data-confirmation-feedback]');
    const nodes = [...form.querySelectorAll('[data-confirmation-field]')];
    const contactVerified = form.dataset.hasVerifiedContact === 'true';
    const csrf = form.querySelector('input[name="_token"]')?.value || '';
    const states = nodes.map(node => ({
        node,
        name: node.dataset.fieldName,
        required: node.dataset.required === 'true',
        input: node.querySelector('[data-confirmation-input]'),
        button: node.querySelector('[data-confirmation-save-field]'),
        countdown: node.querySelector('[data-confirmation-countdown]'),
        error: node.querySelector('[data-confirmation-error]'),
        saved: node.dataset.savedValue || '',
    }));
    const fieldsByName = new Map(states.map(state => [state.name, state]));
    let busy = false;
    const minCooldownMs = 5000;

    function current(state) {
        return String(state.input.value || '').trim();
    }

    function isDirty(state) {
        return current(state) !== state.saved;
    }

    function clearErrors() {
        states.forEach(state => { state.error.textContent = ''; state.error.hidden = true; });
        feedback.textContent = '';
        feedback.classList.remove('is-error');
    }

    function refresh() {
        const missing = states.filter(state => state.required && !state.saved.trim());
        const pending = states.some(isDirty);
        states.forEach(state => {
            state.button.disabled = busy || !isDirty(state);
            state.node.classList.toggle('is-dirty', isDirty(state));
        });
        allButton.disabled = busy || !pending;
        submit.disabled = busy || missing.length > 0 || pending || !contactVerified;
        status.textContent = missing.length === 0 ? 'Заполнено' : 'Требует заполнения';
        status.classList.toggle('app-confirmation__state--done', missing.length === 0);
        if (!requirement) return;

        const needed = [...missing];
        if (!contactVerified) needed.push({ name: 'contact', id: 'confirmation-main-contact', label: 'основной контакт' });
        if (needed.length === 0) {
            requirement.textContent = 'Все обязательные данные заполнены. Теперь можно подтвердить аккаунт.';
            return;
        }
        const first = needed[0];
        const anchor = document.createElement('a');
        const fieldId = fieldsByName.get(first.name)?.input.id || 'confirmation-main-contact';
        anchor.href = '#' + fieldId;
        anchor.dataset.confirmationMissingLink = '';
        anchor.textContent = first.name === 'contact'
            ? 'подтвердить основной контакт'
            : 'заполнить ' + first.node.querySelector('label').firstChild.textContent.trim().toLowerCase();
        requirement.replaceChildren('Для подтверждения аккаунта необходимо ', anchor,
            needed.length > 1 ? ' и ещё ' + (needed.length - 1) : '', '.');
    }

    function setBusy(enabled, selected = [], all = false) {
        busy = enabled;
        card.classList.toggle('is-saving', enabled && all);
        card.setAttribute('aria-busy', String(enabled));
        // An individual save locks its own field; "Save all" locks the entire profile.
        states.forEach(state => { state.input.disabled = enabled && (all || selected.includes(state)); });
        refresh();
    }

    function displayValidation(message, field) {
        if (field && fieldsByName.has(field)) {
            const state = fieldsByName.get(field);
            state.error.textContent = message;
            state.error.hidden = false;
        } else {
            feedback.textContent = message;
            feedback.classList.add('is-error');
        }
    }

    async function save(selected, button, countdownNode) {
        if (busy || selected.length === 0) return;
        const values = Object.fromEntries(selected.map(state => [state.name, current(state)]));
        clearErrors();
        setBusy(true, selected, button === allButton);
        const started = Date.now();
        let interval;

        function tick() {
            const remaining = Math.max(0, Math.ceil((minCooldownMs - (Date.now() - started)) / 1000));
            if (countdownNode) {
                countdownNode.textContent = remaining ? remaining + ' с' : '…';
                countdownNode.hidden = false;
            }
            if (button === allButton) {
                allButton.textContent = remaining ? 'Сохранение · ' + remaining + ' с' : 'Сохранение...';
            }
        }
        tick();
        interval = setInterval(tick, 200);

        try {
            const result = await Promise.allSettled([
                fetch(form.dataset.saveUrl, {
                    method: 'PATCH',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify(values),
                }).then(async response => {
                    const data = await response.json().catch(() => ({}));
                    if (!response.ok) {
                        const failure = new Error(data.message || 'Не удалось сохранить данные.');
                        failure.data = data;
                        throw failure;
                    }
                    return data;
                }),
                new Promise(resolve => setTimeout(resolve, minCooldownMs)),
            ]);
            const request = result[0];
            if (request.status === 'rejected') throw request.reason;
            const returned = request.value.values || {};
            selected.forEach(state => {
                if (!Object.prototype.hasOwnProperty.call(returned, state.name)) return;
                const saved = String(returned[state.name] ?? '');
                state.saved = saved;
                state.node.dataset.savedValue = saved;
                state.input.value = saved;
            });
            feedback.textContent = 'Изменения сохранены.';
        } catch (error) {
            const entries = error.data?.errors || {};
            const fields = Object.keys(entries);
            if (fields.length) {
                fields.forEach(key => displayValidation(entries[key]?.[0] || 'Некорректное значение.', key));
            } else {
                displayValidation(error.message || 'Ошибка сохранения.');
            }
        } finally {
            clearInterval(interval);
            if (countdownNode) {
                countdownNode.textContent = '';
                countdownNode.hidden = true;
            }
            allButton.textContent = 'Сохранить всё';
            setBusy(false);
        }
    }

    states.forEach(state => {
        state.input.addEventListener('input', () => {
            state.error.hidden = true;
            feedback.textContent = '';
            refresh();
        });
        state.input.addEventListener('change', refresh);
        state.button.addEventListener('click', () => save([state], state.button, state.countdown));
    });

    allButton.addEventListener('click', () => save(states.filter(isDirty), allButton));
    form.addEventListener('submit', event => {
        if (busy || !contactVerified || states.some(state => isDirty(state) || (state.required && !state.saved.trim()))) {
            event.preventDefault();
            refresh();
        }
    });
    requirement?.addEventListener('click', event => {
        const link = event.target.closest('[data-confirmation-missing-link]');
        if (!link) return;
        const target = document.getElementById(link.hash.substring(1));
        if (!target) return;
        event.preventDefault();
        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
        // Only inputs/selects receive editing focus; contact section is not focusable.
        if (target.matches('input,select,textarea')) target.focus({ preventScroll: true });
    });
    refresh();
}
