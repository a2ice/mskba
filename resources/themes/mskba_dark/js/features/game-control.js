const openGameModal = (id) => {
    const modal = document.querySelector(`[data-modal="${id}"]`);
    if (!modal) return;
    modal.hidden = false;
    modal.classList.add('is-open');
    document.body.classList.add('modal-open', 'content-modal-open');
};

const closeGameModal = (id) => document.querySelector(`[data-modal="${id}"] [data-modal-action="close"]`)?.click();

function initGameControl(root) {
    const form = root.querySelector('[data-game-live-statistics]');
    if (!form) return;

    const message = form.querySelector('[data-game-live-message]');
    const scoreboard = root.querySelector('[data-game-scoreboard]');
    let activePlayer = null;

    const setLoading = (surface, loading) => surface?.classList.toggle('is-image-upload-loading', loading);
    const field = (player, name) => player?.querySelector(`[data-game-stat-field="${name}"]`);
    const value = (player, name) => Number(field(player, name)?.value || 0);
    const setMessage = (text, error = false) => {
        if (!message) return;
        message.hidden = false;
        message.textContent = text;
        message.className = `alert ${error ? 'alert-danger' : 'alert-success'}`;
    };

    const applyResponse = (data) => {
        Object.entries(data.scores || {}).forEach(([slot, score]) => {
            const normalized = score ?? 0;
            form.querySelector(`[data-game-score-input="${slot}"]`).value = normalized;
            root.querySelector(`[data-game-visible-score="${slot}"]`).textContent = normalized;
        });
        Object.entries(data.player_points || {}).forEach(([playerId, points]) => {
            form.querySelector(`[data-game-player="${playerId}"] [data-game-player-points]`).textContent = points;
        });
    };

    const saveStatistics = async () => {
        setLoading(form, true);
        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || 'Не удалось сохранить статистику.');
            applyResponse(data);
            setMessage('Изменения сохранены.');
            return data;
        } catch (error) {
            setMessage(error.message, true);
            throw error;
        } finally {
            setLoading(form, false);
        }
    };

    root.querySelector('[data-game-score-open]')?.addEventListener('click', () => openGameModal('game-score-modal'));
    const scoreForm = document.querySelector('[data-game-score-form]');
    if (scoreForm) scoreForm.onsubmit = async (event) => {
        event.preventDefault();
        setLoading(scoreboard, true);
        const body = new FormData(scoreForm);
        body.append('_method', 'PATCH');
        try {
            const response = await fetch(scoreboard.dataset.scoreUrl, { method: 'POST', body, headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || 'Не удалось сохранить счёт.');
            applyResponse(data);
            closeGameModal('game-score-modal');
            setMessage('Счёт сохранён.');
        } catch (error) { setMessage(error.message, true); } finally { setLoading(scoreboard, false); }
    };

    form.querySelectorAll('[data-game-stat-increment]').forEach((button) => button.addEventListener('click', async () => {
        const player = button.closest('[data-game-player]');
        const input = field(player, button.dataset.gameStatIncrement);
        if (!input) return;
        input.value = Number(input.value || 0) + 1;
        try { await saveStatistics(); } catch { input.value = Math.max(0, Number(input.value) - 1); }
    }));

    form.querySelectorAll('[data-game-shot-open]').forEach((button) => button.addEventListener('click', () => {
        activePlayer = button.closest('[data-game-player]');
        const shotForm = document.querySelector('[data-game-shot-form]');
        shotForm?.reset();
        openGameModal('game-shot-modal');
    }));

    form.querySelectorAll('[data-game-inline-open]').forEach((button) => button.addEventListener('click', () => {
        activePlayer = button.closest('[data-game-player]');
        const fields = document.querySelector('[data-game-inline-fields]');
        if (!fields) return;
        fields.innerHTML = '';
        activePlayer.querySelectorAll('[data-game-stat-field]').forEach((input) => {
            const label = document.createElement('label');
            label.className = 'field';
            label.innerHTML = `<span class="form-label">${input.dataset.statLabel}</span><input class="form-control" type="number" min="0" max="999" name="${input.dataset.gameStatField}" value="${input.value}">`;
            fields.append(label);
        });
        openGameModal('game-inline-statistics-modal');
    }));

    const shotForm = document.querySelector('[data-game-shot-form]');
    if (shotForm) shotForm.onsubmit = async (event) => {
        event.preventDefault();
        if (!activePlayer) return;
        const range = new FormData(shotForm).get('range');
        const attempted = field(activePlayer, `${range}_attempted`);
        const made = field(activePlayer, `${range}_made`);
        if (!attempted || !made) return;
        const isMade = Boolean(shotForm.querySelector('[name="made"]:checked'));
        const scoreInput = form.querySelector(`[data-game-score-input="${activePlayer.dataset.gamePlayerSide}"]`);
        const previousAttempted = attempted.value;
        const previousMade = made.value;
        const previousScore = scoreInput?.value;
        attempted.value = Number(attempted.value || 0) + 1;
        if (isMade) {
            made.value = Number(made.value || 0) + 1;
            if (scoreInput) scoreInput.value = Number(scoreInput.value || 0) + (range === 'three' ? 3 : 2);
        }
        try {
            await saveStatistics();
            closeGameModal('game-shot-modal');
        } catch {
            attempted.value = previousAttempted;
            made.value = previousMade;
            if (scoreInput) scoreInput.value = previousScore;
        }
    };

    const inlineForm = document.querySelector('[data-game-inline-form]');
    if (inlineForm) inlineForm.onsubmit = async (event) => {
        event.preventDefault();
        if (!activePlayer) return;
        new FormData(inlineForm).forEach((newValue, name) => {
            const input = field(activePlayer, name);
            if (input) input.value = Math.max(0, Number(newValue || 0));
        });
        try { await saveStatistics(); closeGameModal('game-inline-statistics-modal'); } catch { /* keep modal open */ }
    };

    const renderReview = () => {
        const review = document.querySelector('[data-game-final-review]');
        const table = review?.querySelector('[data-game-review-table]');
        if (!review || !table) return;
        const scores = { A: 0, B: 0 };
        const rows = [];
        const isStreetball = form.dataset.scoringType === 'streetball';
        form.querySelectorAll('[data-game-player]').forEach((player) => {
            const points = value(player, 'close_made') * (isStreetball ? 1 : 2) + value(player, 'mid_made') * (isStreetball ? 1 : 2) + value(player, 'three_made') * (isStreetball ? 2 : 3) + value(player, 'free_throw_made');
            scores[player.dataset.gamePlayerSide] += points;
            rows.push(`<tr><th>${player.querySelector('.game-live-player__identity strong').textContent}</th><td>${points}</td><td>${value(player, 'assists')}</td><td>${value(player, 'defensive_rebounds') + value(player, 'offensive_rebounds')}</td><td>${value(player, 'fouls')}</td></tr>`);
        });
        Object.entries(scores).forEach(([slot, score]) => {
            form.querySelector(`[data-game-score-input="${slot}"]`).value = score;
            review.querySelector(`[data-review-score="${slot}"]`).textContent = score;
        });
        table.innerHTML = `<div class="game-statistics-table-wrap"><table class="game-statistics-table"><thead><tr><th>Игрок</th><th>Очки</th><th>Передачи</th><th>Подборы</th><th>Фолы</th></tr></thead><tbody>${rows.join('')}</tbody></table></div>`;
    };

    root.querySelector('[data-game-review-open]')?.addEventListener('click', () => {
        renderReview();
        openGameModal('game-final-review-modal');
    });
    document.querySelector('[data-game-recalculate]')?.addEventListener('click', renderReview);
    const completeButton = document.querySelector('[data-game-complete-confirm]');
    if (completeButton) completeButton.onclick = async () => {
        const review = document.querySelector('[data-game-final-review]');
        renderReview();
        setLoading(review, true);
        try {
            const response = await fetch(review.dataset.completeUrl, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || 'Не удалось завершить игру.');
            applyResponse(data);
            closeGameModal('game-final-review-modal');
            window.location.assign(data.redirect_url || window.location.href);
        } catch (error) {
            const paragraph = review.querySelector('p');
            paragraph.textContent = error.message;
            paragraph.classList.add('text-danger');
        } finally { setLoading(review, false); }
    };

    root.querySelector('[data-game-cancel]')?.addEventListener('click', async (event) => {
        const button = event.currentTarget;
        if (!window.confirm('Отменить игру как несостоявшуюся?')) return;
        setLoading(form, true);
        try {
            const body = new FormData();
            body.append('_method', 'PATCH');
            const response = await fetch(button.dataset.cancelUrl, {
                method: 'POST',
                body,
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
            });
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || 'Не удалось отменить игру.');
            window.location.assign(data.redirect_url || window.location.href);
        } catch (error) {
            setMessage(error.message, true);
        } finally {
            setLoading(form, false);
        }
    });

    root.querySelectorAll('[data-roster-editor-open]').forEach((button) => button.addEventListener('click', () => {
        const editor = root.querySelector('[data-roster-editor]');
        if (editor) { editor.hidden = false; editor.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
    }));
    root.querySelector('[data-roster-editor-close]')?.addEventListener('click', () => { root.querySelector('[data-roster-editor]').hidden = true; });

    const rosterForm = root.querySelector('[data-game-roster-ajax]');
    rosterForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const surface = rosterForm.closest('[data-roster-editor]');
        setLoading(surface, true);
        try {
            const response = await fetch(rosterForm.action, { method: 'POST', body: new FormData(rosterForm), headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || 'Не удалось сохранить состав.');
            const page = await fetch(window.location.href, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            const html = new DOMParser().parseFromString(await page.text(), 'text/html');
            const replacement = html.querySelector('[data-game-control]');
            if (replacement) { root.replaceWith(replacement); initGameControl(replacement); }
        } catch (error) { setMessage(error.message, true); setLoading(surface, false); }
    });
}

document.querySelectorAll('[data-game-control]').forEach(initGameControl);
