document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-event-wizard]').forEach(initSplitGameStep);
});

function initSplitGameStep(form) {
    if (!(form instanceof HTMLFormElement) || form.dataset.splitGameStepReady === '1') return;
    form.dataset.splitGameStepReady = '1';

    const gameStep = form.querySelector('[data-wizard-step="game"]');
    const scheduleStep = form.querySelector('[data-wizard-step="schedule"]');
    const nextButton = form.querySelector('[data-wizard-next]');
    const backButton = form.querySelector('[data-wizard-back]');
    const progressTitle = form.querySelector('[data-wizard-progress-title]');
    const progressCount = form.querySelector('[data-wizard-progress-count]');
    const progressBar = form.querySelector('[data-wizard-progress-bar]');
    const typeRadios = Array.from(form.querySelectorAll('[data-wizard-type]'));

    if (!gameStep || !scheduleStep || !nextButton || !backButton) return;

    const recruitmentFieldset = Array.from(gameStep.querySelectorAll('fieldset'))
        .find((fieldset) => fieldset.querySelector('[data-recruitment-mode]'));
    const applicationsToggle = gameStep.querySelector('input[name="game_accepts_applications"]:not([type="hidden"])')
        ?.closest('.event-wizard-toggle');

    if (!recruitmentFieldset || !applicationsToggle) return;

    const headingTitle = gameStep.querySelector('.event-wizard-step__heading h2');
    const headingCopy = gameStep.querySelector('.event-wizard-step__heading p');
    if (headingTitle) headingTitle.textContent = 'Формат игры';
    if (headingCopy) headingCopy.textContent = 'Выберите формат. Правила, размеры сторон и режим времени подстроятся автоматически.';
    gameStep.dataset.stepTitle = 'Формат игры';

    const recruitmentStep = document.createElement('section');
    recruitmentStep.className = 'event-wizard-step';
    recruitmentStep.dataset.wizardRecruitmentStep = '1';
    recruitmentStep.hidden = true;
    recruitmentStep.innerHTML = `
        <div class="event-wizard-step__heading">
            <span class="event-wizard-step__number">03</span>
            <div>
                <h2>Как собираем команды?</h2>
                <p>Определите способ формирования сторон и можно ли участникам подавать заявки.</p>
            </div>
        </div>
    `;
    recruitmentStep.append(recruitmentFieldset, applicationsToggle);
    scheduleStep.before(recruitmentStep);

    let virtualVisible = false;
    let allowNativeNavigation = false;

    const currentType = () => typeRadios.find((radio) => radio.checked)?.value || '';
    const isGame = () => currentType() === 'game';
    const visibleNativeStep = () => form.querySelector('[data-wizard-step]:not([hidden])');

    function setVirtualVisible(visible) {
        virtualVisible = visible;
        recruitmentStep.hidden = !visible;
        if (visible) {
            gameStep.hidden = true;
            scheduleStep.hidden = true;
        }
        refreshProgress();
        if (visible) {
            window.scrollTo({
                top: Math.max(0, form.getBoundingClientRect().top + window.scrollY - 110),
                behavior: 'smooth',
            });
        }
    }

    function validateRecruitmentStep() {
        const controls = Array.from(recruitmentStep.querySelectorAll('input, select, textarea'))
            .filter((control) => !control.disabled && control.type !== 'hidden');
        for (const control of controls) {
            if (!control.checkValidity()) {
                control.reportValidity();
                return false;
            }
        }
        return true;
    }

    function refreshStepNumbers() {
        if (!isGame()) return;
        const numbers = {
            type: '01',
            game: '02',
            schedule: '04',
            venue: '05',
            participants: '06',
            details: '07',
            publication: '08',
        };
        Object.entries(numbers).forEach(([key, value]) => {
            const number = form.querySelector(`[data-wizard-step="${key}"] .event-wizard-step__number`);
            if (number) number.textContent = value;
        });
        const virtualNumber = recruitmentStep.querySelector('.event-wizard-step__number');
        if (virtualNumber) virtualNumber.textContent = '03';
    }

    function refreshProgress() {
        window.requestAnimationFrame(() => {
            if (!isGame()) {
                recruitmentStep.hidden = true;
                virtualVisible = false;
                return;
            }

            refreshStepNumbers();
            if (virtualVisible) {
                if (progressTitle) progressTitle.textContent = 'Как собираем команды?';
                if (progressCount) progressCount.textContent = '3 из 9';
                if (progressBar) progressBar.style.width = `${(3 / 9) * 100}%`;
                return;
            }

            const step = visibleNativeStep();
            const key = step?.dataset.wizardStep;
            const indexByKey = {
                type: 1,
                game: 2,
                schedule: 4,
                venue: 5,
                participants: 6,
                details: 7,
                publication: 8,
                review: 9,
            };
            const index = indexByKey[key];
            if (!index) return;
            if (progressCount) progressCount.textContent = `${index} из 9`;
            if (progressBar) progressBar.style.width = `${(index / 9) * 100}%`;
            if (key === 'game' && progressTitle) progressTitle.textContent = 'Формат игры';
        });
    }

    nextButton.addEventListener('click', (event) => {
        if (!isGame() || allowNativeNavigation) return;

        if (virtualVisible) {
            event.preventDefault();
            event.stopImmediatePropagation();
            if (!validateRecruitmentStep()) return;
            setVirtualVisible(false);
            allowNativeNavigation = true;
            nextButton.click();
            allowNativeNavigation = false;
            refreshProgress();
            return;
        }

        const native = visibleNativeStep();
        if (native?.dataset.wizardStep === 'game') {
            event.preventDefault();
            event.stopImmediatePropagation();

            const gameControls = Array.from(gameStep.querySelectorAll('input, select, textarea'))
                .filter((control) => !control.disabled && control.type !== 'hidden');
            for (const control of gameControls) {
                if (!control.checkValidity()) {
                    control.reportValidity();
                    return;
                }
            }
            setVirtualVisible(true);
        }
    }, true);

    backButton.addEventListener('click', (event) => {
        if (!isGame() || allowNativeNavigation) return;

        if (virtualVisible) {
            event.preventDefault();
            event.stopImmediatePropagation();
            setVirtualVisible(false);
            gameStep.hidden = false;
            refreshProgress();
            return;
        }

        const native = visibleNativeStep();
        if (native?.dataset.wizardStep === 'schedule') {
            event.preventDefault();
            event.stopImmediatePropagation();

            allowNativeNavigation = true;
            backButton.click();
            allowNativeNavigation = false;
            setVirtualVisible(true);
        }
    }, true);

    typeRadios.forEach((radio) => radio.addEventListener('change', () => {
        if (!isGame()) setVirtualVisible(false);
        refreshProgress();
    }));

    const observer = new MutationObserver(refreshProgress);
    form.querySelectorAll('[data-wizard-step]').forEach((step) => observer.observe(step, {
        attributes: true,
        attributeFilter: ['hidden'],
    }));

    refreshProgress();
}
