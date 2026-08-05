const managementRoot = document.querySelector('[data-team-management]');

if (managementRoot) {
    managementRoot.querySelectorAll('.team-member-management-card').forEach((card) => {
        const person = card.querySelector(':scope > .team-person');
        const panels = [...card.querySelectorAll(':scope > .team-member-panel')];

        if (!person || panels.length === 0) {
            return;
        }

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'team-member-card-toggle';
        button.setAttribute('aria-expanded', 'false');
        button.setAttribute('aria-controls', `${card.id}-details`);
        button.innerHTML = '<span>Подробнее</span><i class="ti ti-chevron-down" aria-hidden="true"></i>';

        const details = document.createElement('div');
        details.id = `${card.id}-details`;
        details.className = 'team-member-card-details';

        panels[0].before(details);
        panels.forEach((panel) => details.append(panel));
        person.append(button);

        button.addEventListener('click', () => {
            const expanded = card.classList.toggle('is-details-open');
            button.setAttribute('aria-expanded', expanded ? 'true' : 'false');

            if (!expanded) {
                details.querySelectorAll('details[open]').forEach((panel) => {
                    panel.open = false;
                });
            }
        });
    });
}
