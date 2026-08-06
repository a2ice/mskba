document.addEventListener('DOMContentLoaded', async () => {
    const root = document.querySelector('[data-game-control]');

    if (!root) return;

    const lifecycleUrl = root.dataset.gameLifecycleUrl || legacyLifecycleUrl();
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

function legacyLifecycleUrl() {
    const match = window.location.pathname.match(/^\/events\/([^/]+)$/);
    return match ? `/game-lifecycle/${encodeURIComponent(decodeURIComponent(match[1]))}` : null;
}

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

    root.querySelectorAll('[data-roster-editor-open], .game-control-editor, [data-game-cancel]').forEach((element) => {
        if (state.started) element.hidden = true;
    });

    renderLineupEditor(root, state);

    const actions = lifecycleActions(root);
    actions.querySelectorAll('[data-game-lifecycle-action]').forEach((button) => button.remove());

    if (state.can_start) {
        actions.append(createLifecycleButton('Начать игру', 'ti-player-play-filled', state.start_url, 'start'));
    }
    if (state.can_end) {
        actions.append(createLifecycleButton('Закончить игру', 'ti-player-stop-filled', state.end_url, 'end'));
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

function renderLineupEditor(root, state) {
    root.querySelector('[data-game-lineup-editor]')?.remove();
    if (!state.can_manage_lineup || !state.roster) return;

    const section = document.createElement('section');
    section.className = 'section-card game-lineup-editor';
    section.dataset.gameLineupEditor = '';
    section.innerHTML = '<h2>Стартовый состав и капитаны</h2><p class="form-hint">Выберите точное количество стартовых игроков для каждой стороны. Остальные останутся в запасе.</p>';

    const form = document.createElement('form');
    form.className = 'game-roster-grid';

    Object.entries(state.roster).forEach(([slot, side]) => {
        const fieldset = document.createElement('fieldset');
        fieldset.className = 'game-side-card';
        fieldset.innerHTML = `<legend>${escapeHtml(side.name)} · старт ${side.required_starters}</legend>`;

        side.players.forEach((player) => {
            const row = document.createElement('div');
            row.className = 'game-lineup-player';
            row.innerHTML = `
                <strong>${escapeHtml(player.name)}</strong>
                <label class="form-check">
                    <input class="form-check-input" type="checkbox" name="starters[${slot}][]" value="${player.user_id}" ${player.lineup_role === 'starter' ? 'checked' : ''}>
                    <span class="form-check-label">Старт</span>
                </label>
                <label class="form-check">
                    <input class="form-check-input" type="radio" name="captains[${slot}]" value="${player.user_id}" ${player.is_captain ? 'checked' : ''}>
                    <span class="form-check-label">Капитан</span>
                </label>`;
            fieldset.append(row);
        });
        form.append(fieldset);
    });

    const actions = document.createElement('div');
    actions.className = 'game-roster-editor__actions';
    actions.innerHTML = '<button class="btn btn--primary btn--sm" type="submit">Сохранить старт и капитанов</button>';
    form.append(actions);
    section.append(form);

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const body = new FormData(form);
        body.append('_method', 'PUT');
        const button = form.querySelector('button[type="submit"]');
        button.disabled = true;
        try {
            await requestJson(state.lineup_update_url, { method: 'POST', body });
            window.location.reload();
        } catch (error) {
            button.disabled = false;
            showLifecycleMessage(root, error.message, true);
        }
    });

    const scoreboard = root.querySelector('[data-game-scoreboard]');
    scoreboard?.after(section);
}

function lifecycleActions(root) {
    let actions = root.querySelector('.game-lifecycle-actions');
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
    button.className = action === 'end' ? 'btn btn--danger' : 'game-complete-button';
    button.dataset.gameLifecycleAction = action;
    button.innerHTML = `<i class="ti ${icon}"></i>${label}`;

    button.addEventListener('click', async () => {
        button.disabled = true;
        try {
            await requestJson(url, { method: 'POST' });
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
