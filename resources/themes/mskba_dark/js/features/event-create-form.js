document.querySelectorAll('[data-event-create-form]').forEach((form) => {
    const title = form.querySelector('[data-event-title]');
    const type = form.querySelector('[name="type"]');
    let generatedTitle = title?.dataset.generatedTitle || '';

    type?.addEventListener('change', () => {
        const prefix = type.selectedOptions[0]?.dataset.titlePrefix || 'Мероприятие';
        const nextGeneratedTitle = `${prefix} - ${form.dataset.currentDate}`;

        if (title && (title.value.trim() === '' || title.value === generatedTitle)) {
            title.value = nextGeneratedTitle;
        }

        generatedTitle = nextGeneratedTitle;
        title?.setAttribute('data-generated-title', generatedTitle);
    });
});
