document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-event-create-form]').forEach((form) => {
        if (!(form instanceof HTMLFormElement)) return;
        const fieldset = form.querySelector('[data-game-team-fields]');
        if (!(fieldset instanceof HTMLElement) || fieldset.dataset.recruitmentFieldsReady === '1') return;
        fieldset.dataset.recruitmentFieldsReady = '1';

        const teamA = fieldset.querySelector('select[name="team_a_id"]');
        const teamB = fieldset.querySelector('select[name="team_b_id"]');
        const sideA = fieldset.querySelector('[name="side_a_size"]');
        const sideB = fieldset.querySelector('[name="side_b_size"]');
        if (!(teamA instanceof HTMLSelectElement) || !(teamB instanceof HTMLSelectElement)) return;

        const teamRow = teamA.closest('.row');
        const intro = fieldset.querySelector('p.form-text');
        const wrapper = document.createElement('div');
        wrapper.className = 'row g-3 mb-3';
        wrapper.innerHTML = `
            <div class="col-md-6 form-group field">
                <label class="form-label">Как набираются участники</label>
                <select class="form-select" name="game_recruitment_mode" data-game-recruitment-mode>
                    <option value="preformed_teams">Готовые команды</option>
                    <option value="individual_draft">Отдельные игроки → balanced-команды</option>
                </select>
            </div>
            <div class="col-md-6 form-group field d-flex align-items-end">
                <label class="d-flex gap-2 align-items-start mb-2">
                    <input type="hidden" name="game_accepts_applications" value="0">
                    <input type="checkbox" name="game_accepts_applications" value="1" checked>
                    <span><strong>Принимать заявки</strong><br><small class="form-text">Организатор всё равно сможет отправлять приглашения.</small></span>
                </label>
            </div>`;
        const legend = fieldset.querySelector('legend');
        if (intro) intro.after(wrapper);
        else legend?.after(wrapper);

        const mode = wrapper.querySelector('[data-game-recruitment-mode]');
        if (!(mode instanceof HTMLSelectElement)) return;

        const sync = () => {
            const individual = mode.value === 'individual_draft';
            if (teamRow instanceof HTMLElement) teamRow.hidden = individual;
            if (individual) {
                teamA.value = '';
                teamB.value = '';
                if (sideA instanceof HTMLSelectElement && sideB instanceof HTMLSelectElement) {
                    sideB.value = sideA.value;
                }
            }
            if (intro) {
                intro.textContent = individual
                    ? 'Игроки подадут заявки после создания игры. Из принятого пула организатор сформирует две сбалансированные команды.'
                    : 'Можно сразу выбрать две команды, выбрать только свою и искать соперника либо оставить обе стороны пустыми и набрать команды заявками/приглашениями.';
            }
        };
        mode.addEventListener('change', sync);
        if (sideA instanceof HTMLSelectElement && sideB instanceof HTMLSelectElement) {
            sideA.addEventListener('change', () => {
                if (mode.value === 'individual_draft') sideB.value = sideA.value;
            });
            sideB.addEventListener('change', () => {
                if (mode.value === 'individual_draft') sideA.value = sideB.value;
            });
        }
        sync();
    });
});
