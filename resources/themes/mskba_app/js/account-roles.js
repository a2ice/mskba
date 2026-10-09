// Task 025: each switch saves one role; no full-role replacement or page reload.
// The server supplies remaining cooldown on page load. A disabled switch never queues a change.
const editor = document.querySelector('[data-role-editor]');

if (editor) {
    const feedback = editor.querySelector('[data-role-feedback]');
    const states = new Map();
    let sidebarRequest = null;
    let sidebarRevision = 0;

    function message(text, error = false) {
        if (!feedback) return;
        feedback.textContent = text;
        feedback.dataset.error = String(error);
    }

    async function refreshSidebar() {
        // The server owns the role-to-menu matrix: never duplicate it here.
        sidebarRequest?.abort();
        const controller = new AbortController();
        sidebarRequest = controller;
        const revision = ++sidebarRevision;

        try {
            const response = await fetch(editor.dataset.roleAccountUrl, {
                credentials: 'same-origin',
                headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
                signal: controller.signal,
            });
            if (!response.ok) return;
            const html = await response.text();
            if (revision !== sidebarRevision || controller.signal.aborted) return;

            const fresh = new DOMParser().parseFromString(html, 'text/html');
            const currentAside = document.querySelector('.app-account-layout__aside');
            const updatedAside = fresh.querySelector('.app-account-layout__aside');
            if (currentAside && updatedAside) {
                // Preserve the mobile accordion state while refreshing role items.
                const wasOpen = currentAside.querySelector('.app-account-nav--mobile')?.open;
                currentAside.innerHTML = updatedAside.innerHTML;
                const mobile = currentAside.querySelector('.app-account-nav--mobile');
                if (mobile && wasOpen) mobile.open = true;
            }
        } catch (error) {
            if (error.name !== 'AbortError') {
                // Role is saved even if refreshing the presentation fails.
                message('Роль сохранена. Меню обновится при следующем открытии страницы.');
            }
        } finally {
            if (sidebarRequest === controller) sidebarRequest = null;
        }
    }

    function updateCooldown(state) {
        const secondsLeft = Math.max(0, Math.ceil((state.cooldownUntil - Date.now()) / 1000));
        state.cooldown.hidden = secondsLeft === 0;
        state.cooldown.textContent = secondsLeft ? secondsLeft + ' с' : '';
        state.input.disabled = state.busy || secondsLeft > 0;
        state.card.classList.toggle('is-cooling-down', secondsLeft > 0);
        if (secondsLeft === 0 && state.cooldownInterval !== null) {
            clearInterval(state.cooldownInterval);
            state.cooldownInterval = null;
        }
    }

    function startCooldown(state, seconds) {
        if (!Number.isFinite(seconds) || seconds <= 0) return;
        state.cooldownUntil = Date.now() + Math.ceil(seconds) * 1000;
        if (state.cooldownInterval !== null) clearInterval(state.cooldownInterval);
        state.cooldownInterval = setInterval(() => updateCooldown(state), 200);
        updateCooldown(state);
    }

    function setBusy(state, busy) {
        state.busy = busy;
        state.card.classList.toggle('is-loading', busy);
        state.card.setAttribute('aria-busy', String(busy));
        state.loader.hidden = !busy;
        updateCooldown(state);
        if (state.link) {
            if (busy) {
                state.link.setAttribute('aria-disabled', 'true');
                state.link.tabIndex = -1;
            } else {
                state.link.removeAttribute('aria-disabled');
                state.link.removeAttribute('tabindex');
            }
        }
    }

    async function save(state, requestedEnabled) {
        try {
            const response = await fetch(state.card.dataset.roleUrl, {
                method: 'PATCH',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ enabled: requestedEnabled }),
            });

            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                if (response.status === 409 && data.code === 'ONBOARDING_REQUIRED') {
                    window.dispatchEvent(new Event('mskba:onboarding-required'));
                }
                if (response.status === 429) {
                    // Another tab may have just changed this role. Restore the
                    // saved value and show its remaining cooldown, not a red error.
                    const retrySeconds = Number(data.retry_after);
                    if (retrySeconds > 0) startCooldown(state, retrySeconds);
                    state.input.checked = state.confirmed;
                    state.parameters.hidden = !state.confirmed;
                    message('');
                    return;
                }
                throw new Error(data.message || 'Не удалось изменить роль. Попробуй ещё раз.');
            }

            state.confirmed = data.enabled === true;
            state.input.checked = state.confirmed;
            state.parameters.hidden = !state.confirmed;
            startCooldown(state, Number(data.retry_after) || 5);
            message(state.confirmed
                ? 'Роль «' + state.name + '» включена.'
                : 'Роль «' + state.name + '» отключена.');
            void refreshSidebar();
        } catch (error) {
            state.input.checked = state.confirmed;
            state.parameters.hidden = !state.confirmed;
            message(error.message || 'Не удалось изменить роль. Попробуй ещё раз.', true);
        } finally {
            setBusy(state, false);
            if (state.hadFocus && !state.input.disabled && state.card.isConnected) {
                state.input.focus({ preventScroll: true });
            }
            state.hadFocus = false;
        }
    }

    editor.querySelectorAll('[data-role-card]').forEach(card => {
        const input = card.querySelector('input[type="checkbox"]');
        const parameters = card.querySelector('[data-role-parameters]');
        const state = {
            card, input, parameters,
            loader: card.querySelector('[data-role-loader]'),
            cooldown: card.querySelector('[data-role-cooldown]'),
            link: parameters?.querySelector('a'),
            name: card.querySelector('.app-roles__copy strong')?.textContent || 'участия',
            confirmed: input.checked,
            cooldownUntil: 0,
            cooldownInterval: null,
            busy: false,
            hadFocus: false,
        };
        states.set(card.dataset.roleId, state);
        // Server-provided remaining time survives page refreshes.
        startCooldown(state, Number(card.dataset.roleCooldownSeconds) || 0);

        input.addEventListener('change', () => {
            if (input.disabled) return;
            state.hadFocus = document.activeElement === input;
            const requestedEnabled = input.checked;
            setBusy(state, true);
            message('');
            void save(state, requestedEnabled);
        });
    });
}
