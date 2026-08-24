document.addEventListener('DOMContentLoaded', () => {
    const presetType = new URLSearchParams(window.location.search).get('type');
    if (!['game', 'game_training', 'training'].includes(presetType)) return;

    document.querySelectorAll('[data-event-wizard]').forEach((form) => {
        if (!(form instanceof HTMLFormElement) || form.dataset.presetEntryAdvanced === '1') return;

        const selectedType = Array.from(form.querySelectorAll('[data-wizard-type]'))
            .find((input) => input.checked)?.value;
        if (selectedType !== presetType) return;

        const visibleStep = Array.from(form.querySelectorAll('[data-wizard-step]'))
            .find((step) => !step.hidden);
        if (visibleStep?.dataset.wizardStep !== 'type') return;

        const nextButton = form.querySelector('[data-wizard-next]');
        if (!(nextButton instanceof HTMLButtonElement)) return;

        form.dataset.presetEntryAdvanced = '1';
        nextButton.click();
    });
});
