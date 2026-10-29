// Shared field-level profile editor for confirmation and the account profile.
// A cooldown limits repeated requests to the SAME field; it never delays
// success/error rendering or locks a field after its AJAX request has ended.
export function initProfileInlineSave(form, { onChange = () => {} } = {}) {
    if (!form) return null;

    const allButton = form.querySelector('[data-inline-profile-save-all]');
    const feedback = form.querySelector('[data-inline-profile-feedback]');
    const card = form.closest('.app-confirmation__card, .app-profile__panel');
    const csrf = form.querySelector('input[name="_token"]')?.value || '';
    const minCooldownMs = 5000;
    const states = [...form.querySelectorAll('[data-inline-profile-field]')].map(node => ({
        node,
        name: node.dataset.fieldName,
        required: node.dataset.required === 'true',
        input: node.querySelector('[data-inline-profile-input]'),
        button: node.querySelector('[data-inline-profile-save]'),
        loader: node.querySelector('[data-inline-profile-loading]'),
        countdown: node.querySelector('[data-inline-profile-cooldown]'),
        tooltipHost: node.querySelector('.app-confirmation__input-wrap') || node,
        error: node.querySelector('[data-inline-profile-error]'),
        saved: node.dataset.savedValue || '',
        saving: false,
        cooldownUntil: 0,
        cooldownTimer: null,
    })).filter(state => state.input && state.name);
    let allBusy = false;

    function current(state) { return String(state.input.value || '').trim(); }
    function dirty(state) { return current(state) !== state.saved; }
    function busy() { return allBusy || states.some(state => state.saving); }
    function refresh() {
        const now = Date.now();
        for (const state of states) {
            const changed = dirty(state);
            const cooling = state.cooldownUntil > now;
            const remaining = cooling ? Math.ceil((state.cooldownUntil - now) / 1000) : 0;
            if (state.button) {
                // During cooldown the button remains hoverable so its native
                // tooltip works, but aria-disabled + save() guard prohibit retries.
                state.button.disabled = allBusy || state.saving || (!changed && !cooling);
                state.button.setAttribute('aria-disabled', String(cooling));
                state.button.setAttribute('aria-label', cooling
                    ? 'ожидайте'
                    : 'Сохранить ' + (state.node.querySelector('label')?.firstChild?.textContent?.trim().toLowerCase() || 'поле'));
                state.button.classList.toggle('is-loading', state.saving);
                state.button.classList.toggle('is-cooling', cooling && !state.saving);
                state.button.setAttribute('aria-busy', String(state.saving));
                state.button.title = cooling ? ''
                    : 'Сохранить ' + (state.node.querySelector('label')?.firstChild?.textContent?.trim().toLowerCase() || 'поле');
            }
            // Native title on a small save button is unreliable (especially
            // when the control is disabled). Show a theme tooltip on the entire
            // field surface, including its input/select, during cooldown only.
            if (cooling && !state.saving) {
                if (state.tooltipHost.getAttribute('data-cooldown-tooltip') !== 'ожидайте') {
                    state.tooltipHost.setAttribute('data-cooldown-tooltip', 'ожидайте');
                }
            } else {
                state.tooltipHost.removeAttribute('data-cooldown-tooltip');
            }
            if (state.loader) state.loader.hidden = !state.saving;
            if (state.countdown) {
                state.countdown.hidden = !cooling || state.saving;
                state.countdown.textContent = cooling && !state.saving ? remaining + 'с' : '';
            }
            state.input.disabled = allBusy || state.saving || state.node.dataset.alwaysDisabled === 'true';
            state.node.classList.toggle('is-dirty', changed);
        }
        if (allButton) {
            // A cooldown is local to its field: Save All can still save OTHER
            // modified fields. The label makes partial availability explicit.
            const available = states.some(state => dirty(state) && state.cooldownUntil <= now);
            const waiting = states.some(state => dirty(state) && state.cooldownUntil > now);
            allButton.disabled = busy() || !available;
            if (!allBusy) allButton.textContent = waiting && available
                ? 'Применить остальные' : 'Применить';
        }
        if (card) {
            card.classList.toggle('is-saving', allBusy);
            card.setAttribute('aria-busy', String(busy()));
        }
        onChange({ states, dirty, busy: busy(), refresh });
    }

    function clearErrors() {
        for (const state of states) {
            if (state.error) {
                state.error.textContent = '';
                state.error.hidden = true;
            }
        }
        if (feedback) {
            feedback.textContent = '';
            feedback.classList.remove('is-error');
        }
    }
    function displayError(message, key) {
        const target = states.find(state => state.name === key);
        if (target?.error) {
            target.error.textContent = message;
            target.error.hidden = false;
        } else if (feedback) {
            feedback.textContent = message;
            feedback.classList.add('is-error');
        }
    }
    function cooldown(state) {
        clearInterval(state.cooldownTimer);
        // Start only after a successful response: first show success
        // immediately, then a visible, independent 5-second countdown.
        state.cooldownUntil = Date.now() + minCooldownMs;
        state.cooldownTimer = setInterval(() => {
            if (Date.now() >= state.cooldownUntil) {
                clearInterval(state.cooldownTimer);
                state.cooldownTimer = null;
                state.cooldownUntil = 0;
            }
            refresh();
        }, 150);
    }

    async function save(selected, saveAll = false) {
        if (allBusy || (saveAll && busy())) return;
        // On a bulk save, other fields remain available while one cools down.
        if (saveAll) selected = selected.filter(state =>
            dirty(state) && !state.saving && state.cooldownUntil <= Date.now());
        if (!selected.length ||
            selected.some(state => state.saving || state.cooldownUntil > Date.now())) return;
        const values = Object.fromEntries(selected.map(state => [state.name, current(state)]));
        clearErrors();
        selected.forEach(state => { state.saving = true; });
        if (saveAll) {
            allBusy = true;
            if (allButton) allButton.textContent = 'Сохраняем…';
        }
        refresh();

        try {
            const response = await fetch(form.dataset.saveUrl || form.action, {
                method: 'PATCH',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(values),
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                const error = new Error(data.message || 'Не удалось сохранить данные.');
                error.data = data;
                throw error;
            }

            const returned = data.values || {};
            selected.forEach(state => {
                if (!Object.prototype.hasOwnProperty.call(returned, state.name)) return;
                const saved = String(returned[state.name] ?? '');
                state.saved = saved;
                state.node.dataset.savedValue = saved;
                state.input.value = saved;
                cooldown(state);
            });
            if (feedback) {
                feedback.textContent = data.message || 'Изменения сохранены.';
                feedback.classList.remove('is-error');
            }
        } catch (error) {
            const entries = error.data?.errors || {};
            if (Object.keys(entries).length) {
                Object.entries(entries).forEach(([name, messages]) =>
                    displayError(messages?.[0] || 'Некорректное значение.', name));
            } else {
                displayError(error.message || 'Ошибка сохранения.');
            }
        } finally {
            selected.forEach(state => { state.saving = false; });
            allBusy = false;
            if (saveAll && allButton) allButton.textContent = 'Применить';
            refresh();
        }
    }

    states.forEach(state => {
        state.input.addEventListener('input', () => {
            if (state.error) state.error.hidden = true;
            if (feedback) feedback.textContent = '';
            refresh();
        });
        state.input.addEventListener('change', refresh);
        state.button?.addEventListener('click', () => save([state]));
    });
    allButton?.addEventListener('click', () => save(states.filter(dirty), true));


    refresh();
    return { states, dirty, busy, refresh, save };
}
