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
    },

    modal(trigger) {
        const triggerElement = $(trigger);
        const action = triggerElement.data('modalAction') || triggerElement.data('modal-action') || 'open';
        const modalTarget = triggerElement.data('modalTarget') || triggerElement.data('modal-target');
        const modal = modalTarget ? $('[data-modal="' + modalTarget + '"]') : triggerElement.closest('[data-modal]');

        if (!modal.length) {
            return;
        }

        if (action === 'close') {
            closeModal(modal);
            return;
        }

        openModal(modal);
    }
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
    $(document).trigger('modal:opened', [modal]);
    modal.find('[autofocus]').first().focus();
}

function closeModal(modal) {
    modal.attr('hidden', true).removeClass('is-open');

    if (!$('.modal.is-open').length) {
        $('body').removeClass('modal-open');
    }

    $(document).trigger('modal:closed', [modal]);
}

bindActionHandlers();
bindModalBackgroundClose();
bindModalEscClose();
