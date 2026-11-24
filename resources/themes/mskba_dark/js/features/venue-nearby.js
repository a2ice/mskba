// Legacy theme uses the same public similarity handler; keep its dialogs native
// and use the project-wide pointerdown + pointerup backdrop dismissal rule.
const bindVenueDialog = (openSelector, dialogSelector) => {
    const dialog = document.querySelector(dialogSelector);
    if (!dialog) return;
    document.querySelector(openSelector)?.addEventListener('click', () => dialog.showModal());
    dialog.querySelectorAll('[data-venue-nearby-close]').forEach(button => {
        button.addEventListener('click', () => dialog.close());
    });
    let pointerStartedOnBackdrop = false;
    dialog.addEventListener('pointerdown', event => {
        pointerStartedOnBackdrop = event.target === dialog;
    });
    dialog.addEventListener('pointerup', event => {
        if (pointerStartedOnBackdrop && event.target === dialog) dialog.close();
        pointerStartedOnBackdrop = false;
    });
    dialog.addEventListener('pointercancel', () => { pointerStartedOnBackdrop = false; });
};

bindVenueDialog('[data-venue-nearby-criteria-open]', '[data-venue-nearby-criteria-dialog]');
bindVenueDialog('[data-venue-interest-count-open]', '[data-venue-interest-count-dialog]');
