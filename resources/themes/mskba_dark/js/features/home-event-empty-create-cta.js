const flow = document.querySelector('[data-home-flow="event"]');
const emptyState = flow?.querySelector('[data-home-event-results-empty]');

if (flow && emptyState && !emptyState.querySelector('[data-home-event-empty-create]')) {
    const copy = document.createElement('span');
    copy.textContent = 'или создайте своё мероприятие';

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'btn btn--primary';
    button.dataset.homeEventEmptyCreate = '';
    button.textContent = 'Создать';

    button.addEventListener('click', () => {
        flow.querySelector('[data-home-flow-tab="create"]')?.click();
    });

    emptyState.append(copy, button);
}
