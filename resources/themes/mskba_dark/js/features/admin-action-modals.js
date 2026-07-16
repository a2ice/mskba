document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-admin-action-modal-open]').forEach((button) => {
        button.addEventListener('click', () => {
            const modal = document.querySelector(`[data-admin-action-modal="${button.dataset.adminActionModalOpen}"]`);

            if (!modal) {
                return;
            }

            openModal(modal);
        });
    });

    document.querySelectorAll('[data-admin-action-modal]').forEach((modal) => {
        modal.querySelectorAll('[data-admin-action-modal-close]').forEach((closeButton) => {
            closeButton.addEventListener('click', () => closeModal(modal));
        });

        modal.querySelectorAll('[data-admin-moderation-comment-submit]').forEach((button) => {
            button.addEventListener('click', (event) => {
                const comment = modal.querySelector('[data-admin-moderation-comment]');
                const input = button.form?.querySelector('[data-admin-moderation-message-input]');

                if (!comment || !input) {
                    return;
                }

                input.value = comment.value.trim();

                if (input.value !== '') {
                    comment.setCustomValidity('');
                    return;
                }

                event.preventDefault();
                comment.setCustomValidity('Укажите комментарий.');
                comment.reportValidity();
                comment.focus();
            });
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }

        const openedModals = Array.from(document.querySelectorAll('[data-admin-action-modal]'))
            .filter((modal) => !modal.hidden);
        const openedModal = openedModals[openedModals.length - 1];

        if (openedModal) {
            closeModal(openedModal);
        }
    });
});

function openModal(modal) {
    modal.hidden = false;
    document.body.classList.add('modal-open');
    modal.querySelector('button, input, textarea, select, a')?.focus();
}

function closeModal(modal) {
    modal.hidden = true;

    if (!document.querySelector('[data-admin-action-modal]:not([hidden])')) {
        document.body.classList.remove('modal-open');
    }
}
