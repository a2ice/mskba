document.querySelectorAll('[data-event-create-form]').forEach((form) => {
    const title = form.querySelector('[data-event-title]');
    const type = form.querySelector('[name="type"]');
    const gameTeamFields = form.querySelector('[data-game-team-fields]');
    let generatedTitle = title?.dataset.generatedTitle || '';

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
    syncTypeFields();
});
