document.addEventListener('DOMContentLoaded', async () => {
    const root = document.querySelector('[data-game-control]');

    if (!root) return;

    const lifecycleUrl = root.dataset.gameLifecycleUrl;
    if (!lifecycleUrl) return;

    try {
        const state = await requestJson(lifecycleUrl);
        applyLifecycleState(root, state);
    } catch (error) {
        if (error.status !== 401 && error.status !== 403) {
            showLifecycleMessage(root, error.message, true);
        }
    }
});

function applyLifecycleState(root, state) {
    const scoreButton = root.querySelector('[data-game-score-open]');
    const statisticsForm = root.querySelector('[data-game-live-statistics]');
    const reviewButton = root.querySelector('[data-game-review-open]');

    if (scoreButton) scoreButton.hidden = !state.can_manage_score;

    if (statisticsForm) {
        statisticsForm.querySelectorAll('.game-live-player__actions button').forEach((button) => {
            button.hidden = !state.can_enter_statistics;
        });
    }

    if (reviewButton) {
        reviewButton.hidden = !state.can_confirm_result;
        if (state.can_confirm_result) {
            reviewButton.innerHTML = '<i class="ti ti-circle-check"></i>Проверить и подтвердить результат';
        }
    }

    root.querySelectorAll('[data-game-composition-save], .game-control-editor, [data-game-cancel]').forEach((element) => {
        if (state.started) element.hidden = true;
    });

    const actions = lifecycleActions(root);
    actions.querySelectorAll('[data-game-lifecycle-action]').forEach((button) => button.remove());

    if (state.can_start) {
        actions.append(createLifecycleButton('Начать игру', 'ti-player-play-filled', state.start_url, 'start'));
    }
    if (state.can_end) {
        actions.append(createLifecycleButton('Закончить игру', 'ti-player-stop-filled', state.end_url, 'end'));
    }
    if (state.can_end_period) {
        actions.append(createLifecycleButton('Закончить период', 'ti-player-pause-filled', state.end_period_url, 'end-period'));
    }
    if (state.can_end_early) {
        actions.append(createLifecycleButton('Закончить игру досрочно', 'ti-player-stop-filled', state.end_early_url, 'end-early'));
    }
    if (state.can_start_next_period) {
        actions.append(createLifecycleButton('Начать следующий период', 'ti-player-track-next-filled', state.start_next_period_url, 'start-period'));
    }

    const hint = lifecycleHint(state);
    const existingHint = root.querySelector('[data-game-lifecycle-hint]');
    if (hint) {
        const element = existingHint || document.createElement('p');
        element.dataset.gameLifecycleHint = '';
        element.className = 'game-lifecycle-hint';
        element.textContent = hint;
        if (!existingHint) actions.before(element);
    } else {
        existingHint?.remove();
    }
}

function lifecycleActions(root) {
    let actions = root.querySelector('[data-game-lifecycle-actions]');
    if (!actions) {
        actions = document.createElement('div');
        actions.className = 'game-lifecycle-actions';
        root.querySelector('.game-control__inner')?.append(actions);
    }
    return actions;
}

function createLifecycleButton(label, icon, url, action) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = ['end', 'end-early'].includes(action) ? 'btn btn--danger' : 'game-complete-button';
    button.dataset.gameLifecycleAction = action;
    button.innerHTML = `<i class="ti ${icon}"></i>${label}`;

    button.addEventListener('click', async () => {
        button.disabled = true;
        try {
            const options = { method: 'POST' };
            if (action === 'end-early') {
                const comment = window.prompt('Укажите причину досрочного завершения игры:')?.trim();
                if (!comment) {
                    button.disabled = false;
                    return;
                }
                options.body = JSON.stringify({ comment });
                options.headers = { 'Content-Type': 'application/json' };
            }
            await requestJson(url, options);
            window.location.reload();
        } catch (error) {
            button.disabled = false;
            showLifecycleMessage(document.querySelector('[data-game-control]'), error.message, true);
        }
    });
    return button;
}

function lifecycleHint(state) {
    if (state.cancelled) return null;
    if (state.completed) return 'Результат игры подтверждён.';
    if (!state.started) return 'Проверьте составы, стартовых игроков и капитанов. Статистика откроется после фактического начала.';
    if (state.timing_mode === 'periods' && state.active_period) return `Идёт период ${state.active_period} из ${state.periods_count}. Фиксируйте счёт и статистику игроков.`;
    if (state.timing_mode === 'periods' && !state.ended) return 'Период завершён. Запустите следующий период, чтобы продолжить ввод.';
    if (!state.ended) return 'Игра идёт. Фиксируйте текущий счёт и статистику игроков.';
    return 'Фактическое проведение завершено. Проверьте итоговые показатели и подтвердите результат.';
}

function showLifecycleMessage(root, message, isError = false) {
    if (!root || !message) return;
    let element = root.querySelector('[data-game-lifecycle-message]');
    if (!element) {
        element = document.createElement('div');
        element.dataset.gameLifecycleMessage = '';
        root.querySelector('.game-control__inner')?.prepend(element);
    }
    element.className = isError ? 'alert alert-danger' : 'alert alert-success';
    element.textContent = message;
}

function escapeHtml(value) {
    const element = document.createElement('div');
    element.textContent = String(value ?? '');
    return element.innerHTML;
}

async function requestJson(url, options = {}) {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
    const response = await fetch(url, {
        ...options,
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
            ...(options.headers || {}),
        },
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) {
        const error = new Error(payload.message || 'Не удалось изменить состояние игры.');
        error.status = response.status;
        throw error;
    }
    return payload;
}
