// The primary venue action belongs to events only. Rental, favorites and
// venue-interest subscriptions are independent actions outside this dialog.
(() => {
    const dialog = document.querySelector('[data-venue-choice-dialog]');
    if (!dialog) return;

    const close = () => { if (dialog.open) dialog.close(); };
    document.addEventListener('click', event => {
        const openButton = event.target.closest?.('[data-venue-choice-open]');
        if (openButton && !openButton.disabled) {
            event.preventDefault();
            if (!dialog.open) dialog.showModal();
            return;
        }
        if (event.target.closest?.('[data-venue-choice-close]')) close();
        if (dialog.open && event.target.closest?.('[data-quick-event-open]')) close();
        const rental = event.target.closest?.('[data-venue-rental-start]');
        if (rental) {
            const courtId = Number(rental.dataset.courtId);
            const calendar = document.querySelector('[data-venue-day-dialog]');
            if (calendar && Number.isSafeInteger(courtId) && courtId > 0) {
                calendar.dispatchEvent(new CustomEvent('mskba:choose-court', {
                    detail: {courtId},
                }));
            }
        }
    }, true);
    let backgroundPressed = false;
    dialog.addEventListener('pointerdown', event => { backgroundPressed = event.target === dialog; });
    dialog.addEventListener('pointerup', event => {
        if (backgroundPressed && event.target === dialog) close();
        backgroundPressed = false;
    });
    // The booking schedule is already divided into courts. A venue-wide
    // calendar must never silently choose an arbitrary hall.
    window.addEventListener('mskba:venue-court-selection-required', () => {
        document.querySelector('#app-venue-schedule')?.scrollIntoView({block: 'start', behavior: 'smooth'});
        window.dispatchEvent(new CustomEvent('mskba:action-toast', {
            detail: {message: 'Выбери зал в расписании, чтобы посмотреть свободное время.'},
        }));
    });
})();