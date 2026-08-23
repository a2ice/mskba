document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-event-wizard]').forEach(initTeamClearControls);
});

function initTeamClearControls(form) {
    const slots = Array.from(form.querySelectorAll('[data-team-slot]'));
    if (!slots.length) return;

    const syncControls = () => {
        slots.forEach((slot) => {
            const hasTeam = slot.classList.contains('has-team');
            let clear = slot.querySelector('[data-team-slot-clear]');

            if (!hasTeam) {
                clear?.remove();
                return;
            }

            if (!clear) {
                clear = document.createElement('button');
                clear.type = 'button';
                clear.className = 'event-wizard-team-slot__clear';
                clear.dataset.teamSlotClear = '1';
                clear.setAttribute('aria-label', 'Убрать выбранную команду');
                clear.setAttribute('title', 'Убрать команду');
                clear.innerHTML = '<i class="ti ti-x" aria-hidden="true"></i>';
                slot.append(clear);

                clear.addEventListener('click', (event) => {
                    event.preventDefault();
                    event.stopPropagation();

                    const selectedLogo = slot.querySelector('.event-wizard-team-slot__logo img')?.src;
                    if (!selectedLogo) return;

                    const matchingCard = Array.from(form.querySelectorAll('.event-wizard-team-card'))
                        .find((card) => card.querySelector('.event-wizard-team-card__logo img')?.src === selectedLogo);

                    if (matchingCard instanceof HTMLButtonElement) {
                        matchingCard.click();
                    }
                });
            }
        });
    };

    const observer = new MutationObserver(syncControls);
    slots.forEach((slot) => observer.observe(slot, {
        attributes: true,
        attributeFilter: ['class'],
        childList: true,
        subtree: true,
    }));

    syncControls();
}