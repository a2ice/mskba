document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-venue-create-wizard]').forEach((form) => {
        const steps = Array.from(form.querySelectorAll('[data-venue-wizard-step]'));
        const back = form.querySelector('[data-venue-wizard-back]');
        const submit = form.querySelector('[data-venue-wizard-submit]');
        let active = form.dataset.initialStep === 'details' ? 1 : 0;

        const showStep = (index, focus = true) => {
            active = index;
            steps.forEach((step, position) => { step.hidden = position !== active; });
            submit.hidden = active === 0;
            form.querySelector('[data-venue-wizard-title]').textContent = active === 0 ? 'Как добавляете площадку?' : 'Основные данные';
            form.querySelector('[data-venue-wizard-count]').textContent = `${active + 1} из ${steps.length}`;
            form.querySelector('[data-venue-wizard-bar]').style.width = `${(active + 1) / steps.length * 100}%`;
            if (focus) steps[active].querySelector('h2').focus();
        };

        const advance = () => {
            const role = form.querySelector('[name="creation_role"]');
            if (role.reportValidity()) showStep(1);
        };
        form.querySelectorAll('[data-venue-wizard-choice]').forEach((choice) => {
            choice.addEventListener('click', (event) => {
                if (event.target.closest('[data-venue-role-tooltip]')) {
                    event.preventDefault();
                    return;
                }
                queueMicrotask(() => {
                    choice.querySelector('input').checked = true;
                    advance();
                });
            });
        });
        form.querySelectorAll('[name="creation_role"]').forEach((input) => {
            input.addEventListener('change', () => { if (active === 0) advance(); });
        });
        back.addEventListener('click', () => {
            if (active === 0) window.location.assign(form.querySelector('[data-venue-wizard-fallback-back]').href);
            else showStep(0);
        });
        form.addEventListener('keydown', (event) => {
            if (active === 0 && event.key === 'Enter' && event.target instanceof HTMLInputElement) {
                event.preventDefault();
                advance();
            }
        });
        form.addEventListener('submit', (event) => {
            if (active === 0) {
                event.preventDefault();
                advance();
            }
        });
        // An invalid field must be visible before the browser tries to focus it.
        form.addEventListener('invalid', (event) => {
            const step = event.target.closest('[data-venue-wizard-step]');
            const index = steps.indexOf(step);
            if (index >= 0 && index !== active) showStep(index, false);
        }, true);
        form.querySelector('[data-venue-wizard-progress]').hidden = false;
        form.querySelector('[data-venue-wizard-fallback-back]').hidden = true;
        back.hidden = false;
        showStep(active, false);
    });
});
