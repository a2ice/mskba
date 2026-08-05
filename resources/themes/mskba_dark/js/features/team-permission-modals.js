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
        modal.hidden = false;
        modal.classList.add('is-open');
        document.body.classList.add('modal-open');
        document.dispatchEvent(new CustomEvent('modal:opened', { detail: { modal } }));
        modal.querySelector('[autofocus]')?.focus();
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
    modal.hidden = true;
    modal.classList.remove('is-open');

    if (!document.querySelector('.modal.is-open')) {
        document.body.classList.remove('modal-open', 'content-modal-open');
    }
});
