document.addEventListener('click', (event) => {
    const openButton = event.target.closest('[data-team-permission-modal]');

    if (openButton) {
        const target = openButton.dataset.teamPermissionModal;
        const modal = target ? document.querySelector(`[data-modal="${CSS.escape(target)}"]`) : null;

        if (!modal) {
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();
        window.MskbaModal?.open(modal);
        return;
    }

    const closeButton = event.target.closest('[data-team-permission-modal-close]');

    if (!closeButton) {
        return;
    }

    const modal = closeButton.closest('[data-modal]');

    if (!modal) {
        return;
    }

    event.preventDefault();
    event.stopImmediatePropagation();
    window.MskbaModal?.close(modal);
});
