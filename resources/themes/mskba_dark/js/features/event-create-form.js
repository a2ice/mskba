document.querySelectorAll('[data-event-create-form]').forEach((form) => {
    const title = form.querySelector('[data-event-title]');
    const type = form.querySelector('[name="type"]');
    const gameTeamFields = form.querySelector('[data-game-team-fields]');
    const gameFormat = form.querySelector('[data-game-format]');
    const scoringType = form.querySelector('[data-game-scoring-type]');
    const timingMode = form.querySelector('[data-game-timing-mode]');
    const sideSizes = form.querySelectorAll('[data-game-side-size]');
    const periodsField = form.querySelector('[data-game-periods-field]');
    const periodsCount = form.querySelector('[data-game-periods-count]');
    let generatedTitle = title?.dataset.generatedTitle || '';

    const syncPeriodsVisibility = () => {
        const usesPeriods = timingMode?.value === 'periods';
        if (periodsField) periodsField.hidden = !usesPeriods;
        if (periodsCount) periodsCount.disabled = !usesPeriods;
    };

    const applyFormatPreset = () => {
        const option = gameFormat?.selectedOptions[0];
        if (!option || gameFormat.value === 'custom') return;

        sideSizes.forEach((field) => { field.value = option.dataset.sideSize; });
        if (scoringType) scoringType.value = option.dataset.scoringType;
        if (timingMode) timingMode.value = option.dataset.timingMode;
        if (periodsCount && option.dataset.periodsCount) periodsCount.value = option.dataset.periodsCount;
        syncPeriodsVisibility();
    };

    const markFormatCustom = () => {
        if (gameFormat) gameFormat.value = 'custom';
        syncPeriodsVisibility();
    };

    const syncTypeFields = () => {
        const prefix = type.selectedOptions[0]?.dataset.titlePrefix || 'Мероприятие';
        const nextGeneratedTitle = `${prefix} - ${form.dataset.currentDate}`;

        if (title && (title.value.trim() === '' || title.value === generatedTitle)) {
            title.value = nextGeneratedTitle;
        }

        generatedTitle = nextGeneratedTitle;
        title?.setAttribute('data-generated-title', generatedTitle);

        if (gameTeamFields) {
            const isGame = type.value === 'game';
            gameTeamFields.hidden = !isGame;
            gameTeamFields.querySelectorAll('select').forEach((field) => {
                field.disabled = !isGame;
            });
        }
    };

    type?.addEventListener('change', syncTypeFields);
    gameFormat?.addEventListener('change', applyFormatPreset);
    scoringType?.addEventListener('change', markFormatCustom);
    timingMode?.addEventListener('change', syncPeriodsVisibility);
    sideSizes.forEach((field) => field.addEventListener('change', markFormatCustom));
    syncTypeFields();
    syncPeriodsVisibility();
});
