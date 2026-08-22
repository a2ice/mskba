import { setupBalancedFormation } from './balanced-formation.js';

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-tournament-formation]').forEach(setupBalancedFormation);
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
    if (!(form instanceof HTMLFormElement) || !(gameFormat instanceof HTMLSelectElement) || !(periodsCount instanceof HTMLSelectElement)) return;

    const timingModeFallback = document.createElement('input');
    timingModeFallback.type = 'hidden';
    timingModeFallback.name = timingMode.name;
    timingModeFallback.disabled = true;
    form.append(timingModeFallback);

    const sync = () => {
        const supportsPeriods = gameFormat.value === 'basketball_5x5';
        if (!supportsPeriods) timingMode.value = 'whole_game';
        timingMode.disabled = !supportsPeriods;
        timingModeFallback.value = timingMode.value;
        timingModeFallback.disabled = supportsPeriods;
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
    const notify = (text, error = false) => {
        if (!(message instanceof HTMLElement)) return;
        message.hidden = false;
        message.textContent = text;
        message.className = `alert mt-3 ${error ? 'alert-danger' : 'alert-success'}`;
    };
    const request = async (url, payload) => {
        const response = await fetch(url, {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify(payload),
        });
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
        if (row.dataset.matchOrderFixed === '1') return;
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
            const marker = document.createTextNode('');
            dragged.replaceWith(marker);
            row.replaceWith(dragged);
            marker.replaceWith(row);
            updatePositions();
        });
    });
}
