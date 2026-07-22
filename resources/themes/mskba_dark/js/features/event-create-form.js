function formatLocalDateTime(date) {
    const pad = (value) => String(value).padStart(2, '0');

    return [
        date.getFullYear(),
        pad(date.getMonth() + 1),
        pad(date.getDate()),
    ].join('-') + `T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

document.querySelectorAll('[data-event-create-form]').forEach((form) => {
    const title = form.querySelector('[data-event-title]');
    const type = form.querySelector('[name="type"]');
    const startsAt = form.querySelector('[data-event-start]');
    const endsAt = form.querySelector('[data-event-end]');
    let generatedTitle = title?.dataset.generatedTitle || '';
    let suggestedEnd = endsAt?.value || '';

    type?.addEventListener('change', () => {
        const prefix = type.selectedOptions[0]?.dataset.titlePrefix || 'Мероприятие';
        const nextGeneratedTitle = `${prefix} - ${form.dataset.currentDate}`;

        if (title && (title.value.trim() === '' || title.value === generatedTitle)) {
            title.value = nextGeneratedTitle;
        }

        generatedTitle = nextGeneratedTitle;
        title?.setAttribute('data-generated-title', generatedTitle);
    });

    const updateEnd = () => {
        if (!startsAt?.value || !endsAt) {
            return;
        }

        const start = new Date(startsAt.value);
        if (Number.isNaN(start.getTime())) {
            return;
        }

        const nextEnd = formatLocalDateTime(new Date(start.getTime() + 60 * 60 * 1000));
        endsAt.min = nextEnd;

        if (endsAt.value === '' || endsAt.value === suggestedEnd || endsAt.value < nextEnd) {
            endsAt.value = nextEnd;
        }

        suggestedEnd = nextEnd;
    };

    startsAt?.addEventListener('change', updateEnd);
    updateEnd();
});
