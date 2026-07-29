import $ from 'jquery';

const handlers = {
    toggleClass(trigger, params) {
        const paramsStr = params || '';
        if (!paramsStr) {
            return;
        }

        const parsedParams = paramsStr.split(';');
        const target = parsedParams[1] ? $(parsedParams[1]) : $(trigger);
        target.toggleClass(parsedParams[0]);

        if (parsedParams[0] === 'nav-shown' && target.is('body')) {
            const isOpen = target.hasClass('nav-shown');

            $('[data-nav-toggle]')
                .attr('aria-expanded', isOpen ? 'true' : 'false')
                .attr('aria-label', isOpen ? 'Закрыть основное меню' : 'Открыть основное меню');
        }
    },

    modal(trigger) {
        const triggerElement = $(trigger);
        const action = triggerElement.data('modalAction') || triggerElement.data('modal-action') || 'open';
        const modalTarget = triggerElement.data('modalTarget') || triggerElement.data('modal-target');
        const modalSection = triggerElement.data('modalSection') || triggerElement.data('modal-section') || '';
        const modalRedirectUrl = triggerElement.data('authRedirectUrl') || triggerElement.data('auth-redirect-url') || '';
        const modal = modalTarget ? $('[data-modal="' + modalTarget + '"]') : triggerElement.closest('[data-modal]');

        if (!modal.length) {
            return;
        }

        if (action === 'close') {
            closeModal(modal);
            return;
        }

        modal.data('modalInitialSection', modalSection);
        modal.data('authRedirectUrl', modalRedirectUrl);
        $('body').removeClass('nav-shown');
        openModal(modal);
    },

    closeAlert(trigger) {
        const alert = $(trigger).closest('.alert');
        if (!alert.length) {
            return;
        }

        alert.remove();
    },

    historyBack() {
        window.history.back();
    },
};

function bindActionHandlers() {
    $(document).on('click', '[data-handler]', function(e) {
        const trigger = $(this);
        const handlerName = trigger.data('handler');
        const handler = handlers[handlerName];
        const params = trigger.data('params') || '';

        if (typeof handler !== 'function') {
            return;
        }

        e.preventDefault();

        handler(this, params);
    });
}

function bindModalBackgroundClose() {
    $(document).on('click', '.modal', function(event) {
        const dialog = $(event.target).closest('.modal__dialog');
        if (dialog.length) {
            return;
        }

        closeModal($(this));
    });
}

function bindModalEscClose() {
    $(document).on('keydown', function(event) {
        if (event.key !== 'Escape') {
            return;
        }

        const openedModal = $('.modal.is-open').last();
        if (!openedModal.length) {
            return;
        }

        closeModal(openedModal);
    });
}

function openModal(modal) {
    modal.removeAttr('hidden').addClass('is-open');
    $('body').addClass('modal-open');

    if (modal.closest('.site-content').length) {
        $('body').addClass('content-modal-open');
    }

    $(document).trigger('modal:opened', [modal]);
    modal.find('[autofocus]').first().focus();
}

function closeModal(modal) {
    modal.attr('hidden', true).removeClass('is-open');

    if (!$('.modal.is-open').length) {
        $('body').removeClass('modal-open content-modal-open');
    } else if (!$('.site-content .modal.is-open').length) {
        $('body').removeClass('content-modal-open');
    }

    $(document).trigger('modal:closed', [modal]);
}

bindActionHandlers();
bindModalBackgroundClose();
bindModalEscClose();
