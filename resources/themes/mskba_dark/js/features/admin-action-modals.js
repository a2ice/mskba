document.addEventListener('DOMContentLoaded', () => {
    const initiallyOpenedModal = document.querySelector('[data-admin-action-modal]:not([hidden])');

    if (initiallyOpenedModal) {
        document.body.classList.add('modal-open');
        initiallyOpenedModal.querySelector('[role="alert"]')?.focus();
    }

    document.querySelectorAll('[data-admin-action-modal^="user-permissions-"] .admin-action-modal__description').forEach((description) => {
        description.textContent = 'Права могут иметь разные значения по умолчанию. Здесь можно изменить персональный набор пользователя.';
    });

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

        modal.querySelectorAll('[data-admin-action-comment-submit]').forEach((button) => {
            button.addEventListener('click', (event) => {
                const comment = modal.querySelector('[data-admin-action-comment]');
                const input = button.form?.querySelector('[data-admin-action-message-input]');

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

        modal.querySelectorAll('[data-admin-action-comment-copy]').forEach((button) => {
            button.addEventListener('click', () => {
                const comment = modal.querySelector('[data-admin-action-comment]');
                const input = button.form?.querySelector('[data-admin-action-message-input]');

                if (comment && input) {
                    input.value = comment.value.trim();
                }
            });
        });

        modal.querySelectorAll('form[data-admin-confirm]').forEach((form) => {
            form.addEventListener('submit', (event) => {
                if (!window.confirm(form.dataset.adminConfirm)) {
                    event.preventDefault();
                }
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
