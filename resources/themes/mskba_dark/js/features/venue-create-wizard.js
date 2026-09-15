document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-venue-create-wizard]').forEach((form) => {
        const steps = Array.from(form.querySelectorAll('[data-venue-wizard-step]'));
        const next = form.querySelector('[data-venue-wizard-next]');
        const back = form.querySelector('[data-venue-wizard-back]');
        const submit = form.querySelector('[data-venue-wizard-submit]');
        let active = form.dataset.initialStep === 'details' ? 1 : 0;

        const showStep = (index, focus = true) => {
            active = index;
            steps.forEach((step, position) => { step.hidden = position !== active; });
            next.hidden = active !== 0;
            back.hidden = active === 0;
            submit.hidden = active === 0;
            form.querySelector('[data-venue-wizard-title]').textContent = active === 0 ? 'Как добавляете площадку?' : 'Основные данные';
            form.querySelector('[data-venue-wizard-count]').textContent = `${active + 1} из ${steps.length}`;
            form.querySelector('[data-venue-wizard-bar]').style.width = `${(active + 1) / steps.length * 100}%`;
            const representative = form.querySelector('[name="creation_role"]:checked')?.value === 'representative';
            form.querySelector('[data-venue-wizard-role-note]').textContent = representative
                ? 'Вы добавляете площадку как представитель. Подтверждение управления и скан документа можно добавить после создания.'
                : 'Вы добавляете место в каталог. Подтверждение полномочий не требуется.';
            if (focus) steps[active].querySelector('h2').focus();
        };

        next.addEventListener('click', () => {
            const role = form.querySelector('[name="creation_role"]');
            if (role.reportValidity()) showStep(1);
        });
        back.addEventListener('click', () => showStep(0));
        form.addEventListener('keydown', (event) => {
            if (active === 0 && event.key === 'Enter' && event.target instanceof HTMLInputElement) {
                event.preventDefault();
                next.click();
            }
        });
        form.addEventListener('submit', (event) => {
            if (active === 0) {
                event.preventDefault();
                next.click();
            }
        });
        // An invalid field must be visible before the browser tries to focus it.
        form.addEventListener('invalid', (event) => {
            const step = event.target.closest('[data-venue-wizard-step]');
            const index = steps.indexOf(step);
            if (index >= 0 && index !== active) showStep(index, false);
        }, true);
        form.querySelector('[data-venue-wizard-progress]').hidden = false;
        showStep(active, false);
    });
});
