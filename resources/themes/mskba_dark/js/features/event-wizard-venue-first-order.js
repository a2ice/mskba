document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-event-wizard]').forEach(initVenueFirstOrder);
});

function initVenueFirstOrder(form) {
    if (!(form instanceof HTMLFormElement) || form.dataset.venueFirstOrderReady === '1') return;
    form.dataset.venueFirstOrderReady = '1';

    const nextButton = form.querySelector('[data-wizard-next]');
    const backButton = form.querySelector('[data-wizard-back]');
    const venueStep = form.querySelector('[data-wizard-step="venue"]');
    const scheduleStep = form.querySelector('[data-wizard-step="schedule"]');
    const participantsStep = form.querySelector('[data-wizard-step="participants"]');
    const recruitmentStep = form.querySelector('[data-wizard-recruitment-step]');
    const progressTitle = form.querySelector('[data-wizard-progress-title]');
    const progressCount = form.querySelector('[data-wizard-progress-count]');
    const progressBar = form.querySelector('[data-wizard-progress-bar]');
    const startInput = form.querySelector('[data-wizard-start]');
    const typeRadios = Array.from(form.querySelectorAll('[data-wizard-type]'));

    if (!nextButton || !backButton || !venueStep || !scheduleStep || !participantsStep) return;

    let bypass = false;
    let venueVisited = !venueStep.hidden;

    const currentType = () => typeRadios.find((radio) => radio.checked)?.value || '';
    const isGame = () => currentType() === 'game';
    const visibleNativeStep = () => Array.from(form.querySelectorAll('[data-wizard-step]'))
        .find((step) => !step.hidden);
    const virtualVisible = () => recruitmentStep && !recruitmentStep.hidden;

    const restoreScheduleDefault = () => {
        if (!(startInput instanceof HTMLInputElement)) return;
        if (startInput.value || !startInput.dataset.venueFirstDefault) return;
        startInput.value = startInput.dataset.venueFirstDefault;
        startInput.dispatchEvent(new Event('change', { bubbles: true }));
    };

    const validateStep = (step) => {
        const controls = Array.from(step.querySelectorAll('input, select, textarea'))
            .filter((control) => !control.disabled && control.type !== 'hidden');
        for (const control of controls) {
            if (!control.checkValidity()) {
                control.reportValidity();
                return false;
            }
        }
        return true;
    };

    const clickWithDisabledControls = (step, button) => {
        const controls = Array.from(step.querySelectorAll('input, select, textarea'));
        const states = controls.map((control) => control.disabled);
        controls.forEach((control) => { control.disabled = true; });
        button.click();
        controls.forEach((control, index) => { control.disabled = states[index]; });
    };

    const advanceUnvisitedScheduleToVenue = () => {
        if (venueVisited || scheduleStep.hidden || bypass) return;
        bypass = true;
        clickWithDisabledControls(scheduleStep, nextButton);
        bypass = false;
        if (!venueStep.hidden) venueVisited = true;
        refreshProgress();
    };

    nextButton.addEventListener('click', (event) => {
        if (bypass || virtualVisible()) return;
        const current = visibleNativeStep();
        const key = current?.dataset.wizardStep;

        if (key === 'venue') {
            event.preventDefault();
            event.stopImmediatePropagation();
            if (!validateStep(venueStep)) return;

            bypass = true;
            backButton.click();
            bypass = false;
            restoreScheduleDefault();
            refreshProgress();
            return;
        }

        if (key === 'schedule' && venueVisited) {
            event.preventDefault();
            event.stopImmediatePropagation();
            if (!validateStep(scheduleStep)) return;

            bypass = true;
            nextButton.click();
            nextButton.click();
            bypass = false;
            refreshProgress();
        }
    }, true);

    backButton.addEventListener('click', (event) => {
        if (bypass || virtualVisible()) return;
        const current = visibleNativeStep();
        const key = current?.dataset.wizardStep;

        if (key === 'venue') {
            event.preventDefault();
            event.stopImmediatePropagation();
            bypass = true;
            backButton.click();
            backButton.click();
            bypass = false;
            refreshProgress();
            return;
        }

        if (key === 'schedule' && venueVisited) {
            event.preventDefault();
            event.stopImmediatePropagation();
            bypass = true;
            clickWithDisabledControls(scheduleStep, nextButton);
            bypass = false;
            refreshProgress();
            return;
        }

        if (key === 'participants' && venueVisited) {
            event.preventDefault();
            event.stopImmediatePropagation();
            bypass = true;
            backButton.click();
            backButton.click();
            bypass = false;
            restoreScheduleDefault();
            refreshProgress();
        }
    }, true);

    function refreshProgress() {
        window.requestAnimationFrame(() => {
            const game = isGame();
            const total = game ? 9 : 7;
            let index = null;
            let title = null;

            if (virtualVisible()) {
                index = 3;
                title = 'Как собираем команды?';
            } else {
                const key = visibleNativeStep()?.dataset.wizardStep;
                const map = game
                    ? { type: 1, game: 2, venue: 4, schedule: 5, participants: 6, details: 7, publication: 8, review: 9 }
                    : { type: 1, venue: 2, schedule: 3, participants: 4, details: 5, publication: 6, review: 7 };
                index = map[key] ?? null;
                if (key === 'venue') title = 'Где?';
                if (key === 'schedule') title = 'Когда?';
                if (key === 'game') title = 'Формат игры';
            }

            if (index !== null) {
                if (progressCount) progressCount.textContent = `${index} из ${total}`;
                if (progressBar) progressBar.style.width = `${(index / total) * 100}%`;
            }
            if (title && progressTitle) progressTitle.textContent = title;

            const numbers = game
                ? { type: '01', game: '02', venue: '04', schedule: '05', participants: '06', details: '07', publication: '08' }
                : { type: '01', venue: '02', schedule: '03', participants: '04', details: '05', publication: '06' };
            Object.entries(numbers).forEach(([key, value]) => {
                const node = form.querySelector(`[data-wizard-step="${key}"] .event-wizard-step__number`);
                if (node) node.textContent = value;
            });
            const virtualNumber = recruitmentStep?.querySelector('.event-wizard-step__number');
            if (virtualNumber) virtualNumber.textContent = '03';
        });
    }

    const venueCopy = venueStep.querySelector('.event-wizard-step__heading p');
    if (venueCopy) {
        venueCopy.textContent = 'Сначала выберите площадку. Свободный интервал проверим после выбора даты, времени и длительности.';
    }

    const observer = new MutationObserver(() => {
        if (!scheduleStep.hidden && !venueVisited) {
            advanceUnvisitedScheduleToVenue();
            return;
        }
        if (!venueStep.hidden) venueVisited = true;
        if (!scheduleStep.hidden && venueVisited) restoreScheduleDefault();
        refreshProgress();
    });
    [venueStep, scheduleStep, participantsStep].forEach((step) => observer.observe(step, {
        attributes: true,
        attributeFilter: ['hidden'],
    }));
    if (recruitmentStep) observer.observe(recruitmentStep, { attributes: true, attributeFilter: ['hidden'] });

    refreshProgress();
}
