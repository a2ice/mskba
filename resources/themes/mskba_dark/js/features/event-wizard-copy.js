document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-event-wizard]').forEach(cleanWizardCopy);
});

function cleanWizardCopy(form) {
    if (!(form instanceof HTMLFormElement)) return;

    const page = form.closest('.event-wizard-page');
    const hero = page?.querySelector('.event-wizard-hero');
    const eyebrow = hero?.querySelector('.eyebrow');
    const heroCopy = hero?.querySelector('p');

    if (eyebrow) eyebrow.textContent = 'Мастер создания';
    if (heroCopy) heroCopy.textContent = 'Настройте мероприятие шаг за шагом.';

    setStepCopy(form, 'type', 'Выберите, что хотите организовать. При необходимости тип можно изменить позже.');
    setStepCopy(form, 'game', 'Выберите формат игры — остальные настройки подстроятся автоматически.');
    setStepCopy(form, 'schedule', 'Укажите дату, время начала и продолжительность.');
    setStepCopy(form, 'venue', 'Выберите площадку. После выбора времени мы проверим, свободна ли она.');

    setChoiceDescription(form, 'type', 'game', 'Игра со счётом и статистикой в реальном времени.');
    setChoiceDescription(form, 'type', 'game_training', 'Тренировка с участниками и несколькими мини-играми.');
    setChoiceDescription(form, 'type', 'training', 'Тренировка или встреча на площадке.');

    setChoiceDescription(form, 'game_recruitment_mode', 'preformed_teams', 'Команды можно выбрать сейчас или добавить позже.');
    setChoiceDescription(form, 'game_recruitment_mode', 'individual_draft', 'Игроков распределим по максимально равным составам.');

    const applications = form.querySelector('input[name="game_accepts_applications"]:not([type="hidden"])')
        ?.closest('.event-wizard-toggle')
        ?.querySelector('small');
    if (applications) applications.textContent = 'Организатор также сможет приглашать участников сам.';
}

function setStepCopy(form, step, text) {
    const copy = form.querySelector(`[data-wizard-step="${step}"] .event-wizard-step__heading p`);
    if (copy) copy.textContent = text;
}

function setChoiceDescription(form, name, value, text) {
    const input = form.querySelector(`input[name="${name}"][value="${value}"]`);
    const description = input?.closest('.event-wizard-choice')?.querySelector('small');
    if (description) description.textContent = text;
}
