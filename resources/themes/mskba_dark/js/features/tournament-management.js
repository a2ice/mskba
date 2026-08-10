document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-tournament-formation]').forEach(setupFormation);
    document.querySelectorAll('[data-tournament-schedule]').forEach(setupSchedule);
    document.querySelectorAll('[data-tournament-match-order]').forEach(setupMatchOrder);
    document.querySelectorAll('[data-match-timing-mode]').forEach(setupMatchTimingMode);
});

function setupMatchTimingMode(timingMode) {
    if (!(timingMode instanceof HTMLSelectElement)) return;
    const form = timingMode.closest('form');
    const gameFormat = form?.querySelector('[data-match-game-format]');
    const periodsCount = form?.querySelector('[data-match-periods-count]');
    const periodsField = periodsCount?.closest('[data-match-periods-field]');
    if (!(gameFormat instanceof HTMLSelectElement) || !(periodsCount instanceof HTMLSelectElement)) return;

    const sync = () => {
        const supportsPeriods = gameFormat.value === 'basketball_5x5';
        if (!supportsPeriods) timingMode.value = 'whole_game';
        timingMode.disabled = !supportsPeriods;
        const periodsEnabled = supportsPeriods && timingMode.value === 'periods';
        periodsCount.disabled = !periodsEnabled;
        periodsField?.classList.toggle('is-disabled', !periodsEnabled);
    };

    gameFormat.addEventListener('change', sync);
    timingMode.addEventListener('change', sync);
    sync();
}

function setupSchedule(root) {
    const form = root.querySelector('[data-tournament-schedule-form]');
    const message = root.querySelector('[data-tournament-schedule-message]');
    if (!(form instanceof HTMLFormElement)) return;
    const choices = [...form.querySelectorAll('input[name="legs"]')].filter((input) => input instanceof HTMLInputElement);
    let selectedValue = choices.find((input) => input.checked)?.value ?? null;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const notify = (text, error = false) => { if (!(message instanceof HTMLElement)) return; message.hidden = false; message.textContent = text; message.className = `alert mt-3 ${error ? 'alert-danger' : 'alert-success'}`; };
    const request = async (url, payload) => {
        const response = await fetch(url, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify(payload) });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message ?? 'Не удалось сформировать расписание.');
        return data;
    };
    choices.forEach((choice) => choice.addEventListener('change', async () => {
        if (!choice.checked || choice.value === selectedValue) return;
        const previousValue = selectedValue;
        if (root.dataset.hasMatches === '1' && !window.confirm('Текущие неназначенные матчи будут заменены. Продолжить?')) {
            choice.checked = false;
            const previousChoice = choices.find((input) => input.value === previousValue);
            if (previousChoice) previousChoice.checked = true;
            return;
        }
        const payload = Object.fromEntries(new FormData(form));
        choices.forEach((input) => { input.disabled = true; });
        try {
            const preview = await request(root.dataset.previewUrl, payload);
            const data = await request(root.dataset.applyUrl, { legs: preview.legs, entries_fingerprint: preview.entries_fingerprint });
            selectedValue = choice.value;
            notify(data.message);
            window.location.reload();
        } catch (error) {
            notify(error.message, true);
            choice.checked = false;
            const previousChoice = choices.find((input) => input.value === previousValue);
            if (previousChoice) previousChoice.checked = true;
            choices.forEach((input) => { input.disabled = false; });
        }
    }));
}

function setupMatchOrder(form) {
    const list = form.querySelector('[data-tournament-match-list]');
    if (!(form instanceof HTMLFormElement) || !(list instanceof HTMLElement)) return;

    let dragged = null;
    const updatePositions = () => {
        [...list.querySelectorAll('[data-tournament-match-row]')].forEach((row, index) => {
            const position = index + 1;
            const input = row.querySelector('[data-match-position]');
            const label = row.querySelector('[data-match-position-label]');
            if (input instanceof HTMLInputElement) input.value = String(position);
            if (label instanceof HTMLElement) label.textContent = String(position);
        });
    };

    list.querySelectorAll('[data-tournament-match-row]').forEach((row) => {
        row.addEventListener('dragstart', (event) => {
            dragged = row;
            row.classList.add('is-dragging');
            if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move';
        });
        row.addEventListener('dragend', () => {
            row.classList.remove('is-dragging');
            dragged = null;
            updatePositions();
        });
        row.addEventListener('dragover', (event) => {
            event.preventDefault();
            if (!(dragged instanceof HTMLElement) || dragged === row) return;
            const bounds = row.getBoundingClientRect();
            list.insertBefore(dragged, event.clientY < bounds.top + bounds.height / 2 ? row : row.nextSibling);
        });
    });
}

function setupFormation(root) {
    const form = root.querySelector('[data-tournament-formation-form]');
    const preview = root.querySelector('[data-tournament-formation-preview]');
    const apply = root.querySelector('[data-tournament-formation-apply]');
    const message = root.querySelector('[data-tournament-formation-message]');
    if (!(form instanceof HTMLFormElement) || !(preview instanceof HTMLElement) || !(apply instanceof HTMLButtonElement)) return;
    let state = null; let dirty = false; let dragged = null;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const notify = (text, error = false) => { if (!(message instanceof HTMLElement)) return; message.hidden = false; message.textContent = text; message.className = `alert mt-3 ${error ? 'alert-danger' : 'alert-success'}`; };
    const metrics = (zone) => {
        const players = [...zone.querySelectorAll('[data-formation-player]')];
        const score = players.reduce((sum, player) => sum + Number(player.dataset.score), 0) / Math.max(1, players.length);
        const coverage = players.reduce((sum, player) => sum + Number(player.dataset.coverage), 0) / Math.max(1, players.length);
        zone.closest('[data-formation-team]')?.querySelector('[data-formation-metrics]')?.replaceChildren(document.createTextNode(`Рейтинг ${score.toFixed(3)} · данные ${Math.round(coverage * 100)}% · ${players.length} игр.`));
    };
    const render = () => {
        preview.replaceChildren(...state.teams.map((team) => {
            const column = document.createElement('div'); column.className = 'col-lg-6'; column.dataset.formationTeam = team.number;
            const card = document.createElement('section'); card.className = 'border rounded p-3 h-100';
            const title = document.createElement('h3'); title.textContent = `Команда ${team.number}`;
            const info = document.createElement('p'); info.className = 'text-muted'; info.dataset.formationMetrics = '';
            const zone = document.createElement('div'); zone.className = 'team-roster-dropzone'; zone.dataset.formationZone = team.number;
            zone.addEventListener('dragover', (event) => event.preventDefault());
            zone.addEventListener('drop', (event) => { event.preventDefault(); if (dragged) { zone.append(dragged); dirty = true; [...preview.querySelectorAll('[data-formation-zone]')].forEach(metrics); } });
            team.players.forEach((player) => {
                const item = document.createElement('article'); item.className = 'team-person team-person--player'; item.draggable = true; item.dataset.formationPlayer = player.id; item.dataset.score = player.score; item.dataset.coverage = player.coverage;
                const body = document.createElement('div'); const name = document.createElement('strong'); name.textContent = player.name; const meta = document.createElement('span'); meta.textContent = `${player.primary_position} · ${Math.round(player.coverage * 100)}% данных`; body.append(name, meta);
                if (player.missing_features?.length) { const missing = document.createElement('small'); missing.className = 'text-muted'; missing.textContent = `Не заполнено: ${player.missing_features.join(', ')}`; body.append(missing); }
                const actions = document.createElement('div'); actions.className = 'team-person__actions'; const move = document.createElement('button'); move.type = 'button'; move.className = 'team-person__icon-action team-person__move'; move.setAttribute('aria-label', 'Переместить в следующую команду'); move.innerHTML = '<i class="ti ti-arrows-exchange" aria-hidden="true"></i>'; actions.append(move); item.append(body, actions);
                move.addEventListener('click', () => { const zones = [...preview.querySelectorAll('[data-formation-zone]')]; const current = item.closest('[data-formation-zone]'); const next = zones[(zones.indexOf(current) + 1) % zones.length]; next.append(item); dirty = true; zones.forEach(metrics); });
                item.addEventListener('dragstart', () => { dragged = item; }); item.addEventListener('dragend', () => { dragged = null; }); zone.append(item);
            });
            card.append(title, info, zone); column.append(card); queueMicrotask(() => metrics(zone)); return column;
        }));
        apply.hidden = false;
    };
    form.addEventListener('submit', async (event) => {
        event.preventDefault(); if (dirty && !window.confirm('Новый расчёт сбросит ручные перемещения. Продолжить?')) return;
        const payload = Object.fromEntries(new FormData(form));
        try { const response = await fetch(root.dataset.previewUrl, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify(payload) }); const data = await response.json(); if (!response.ok) throw new Error(data.message); state = data; dirty = false; render(); notify('Черновик сформирован.'); } catch (error) { notify(error.message, true); }
    });
    apply.addEventListener('click', async () => {
        const teams = [...preview.querySelectorAll('[data-formation-team]')].map((team) => ({ number: Number(team.dataset.formationTeam), user_ids: [...team.querySelectorAll('[data-formation-player]')].map((player) => Number(player.dataset.formationPlayer)) }));
        try { const response = await fetch(root.dataset.applyUrl, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify({ pool_fingerprint: state.pool_fingerprint, teams }) }); const data = await response.json(); if (!response.ok) throw new Error(data.message); notify(data.message); window.location.reload(); } catch (error) { notify(error.message, true); }
    });
}
