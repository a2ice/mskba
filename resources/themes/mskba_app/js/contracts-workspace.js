// Shared native-dialog enhancement for both MSKBA themes.
document.addEventListener('click', (event) => {
    const open = event.target.closest?.('[data-contract-dialog-open]');
    if (open) {
        const id = open.getAttribute('data-contract-dialog-open');
        const dialog = document.getElementById(id);
        if (dialog?.showModal) dialog.showModal();
        return;
    }
    const close = event.target.closest?.('[data-contract-dialog-close]');
    if (close) close.closest('dialog')?.close();
});
document.addEventListener('click', (event) => {
    const dialog = event.target;
    if (!dialog?.matches?.('dialog[data-contract-dialog]')) return;
    const rect = dialog.getBoundingClientRect();
    if (event.clientX < rect.left || event.clientX > rect.right
        || event.clientY < rect.top || event.clientY > rect.bottom) {
        dialog.close();
    }
});
