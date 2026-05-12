import $ from 'jquery';

(() => {
    const actionHandlers = $('[data-handler]');

    actionHandlers.on('click', function() {
        const handlerName = $(this).data('handler');
        const handler = handlers[handlerName];
        let params = [];

        if (handler && typeof handler === 'function') {
            if($(this).data('params')) params = $(this).data('params');
            handler(this, params);
        }
    });

    $(document).on('click', '[data-modal-tab]', function() {
        handlers.modalTab(this);
    });

    $(document).on('keydown', function(event) {
        if (event.key !== 'Escape') {
            return;
        }

        const openedModal = $('.modal.is-open').last();
        if (!openedModal.length) {
            return;
        }

        handlers.modal(null, 'close;' + openedModal.data('modal'));
    });
})();

const handlers = {
    toggleClass(h, params) {
        // data-params="open;body" // 1 - class to toggle mandatory, 2 - selector to toggle class on, default is the element itself
        const paramsStr = params || '';
        if(paramsStr) {
            const params = paramsStr.split(';');
            const target = params[1] ? $(params[1]) : $(h);
            target.toggleClass(params[0]);
        }
    },

    modal(h, params) {
        const paramsStr = typeof params === 'string' ? params : ($(h).data('params') || '');
        const parsedParams = paramsStr ? paramsStr.split(';') : [];

        let action = parsedParams[0] || $(h).data('modalAction') || 'open';
        let modalId = parsedParams[1] || $(h).data('modalTarget') || $(h).data('modal') || '';

        if (action === 'close' && !modalId) {
            modalId = $(h).closest('[data-modal]').data('modal') || '';
        }

        if (!modalId) {
            return;
        }

        const modal = $('[data-modal="' + modalId + '"]');
        if (!modal.length) {
            return;
        }

        if (action === 'open') {
            openModal(modal);
        }

        if (action === 'close') {
            closeModal(modal);
        }

        if (action === 'toggle') {
            if (modal.hasClass('is-open')) {
                closeModal(modal);
            } else {
                openModal(modal);
            }
        }
    },

    modalTab(h) {
        const tab = $(h);
        const tabPanelId = tab.attr('aria-controls');
        const tabGroup = tab.closest('.tabs-wrapper');

        if (!tabPanelId || !tabGroup.length) {
            return;
        }

        const tabs = tabGroup.find('[data-modal-tab]');
        const panels = tabGroup.find('[role="tabpanel"]');
        const panelToShow = tabGroup.find('#' + tabPanelId);

        tabs.attr('aria-selected', 'false').removeClass('is-active');
        tab.attr('aria-selected', 'true').addClass('is-active');

        panels.attr('hidden', true);
        panelToShow.removeAttr('hidden');
    }
};

function openModal(modal) {
    modal.removeAttr('hidden').addClass('is-open');

    const defaultPanel = modal.data('modalDefaultPanel');
    const activePanel = modal.data('modalActivePanel');
    const tabToActivate = activePanel || defaultPanel;

    if (tabToActivate) {
        const tab = modal.find('[aria-controls="' + tabToActivate + '"]').first();
        if (tab.length) {
            handlers.modalTab(tab);
        }
    }

    lockScroll();
}

function closeModal(modal) {
    modal.removeClass('is-open').attr('hidden', true);

    if (!$('.modal.is-open').length) {
        unlockScroll();
    }
}

function lockScroll() {
    $('body').addClass('modal-open');
}

function unlockScroll() {
    $('body').removeClass('modal-open');
}

$(document).on('click', '.modal', function(event) {
    const dialog = $(event.target).closest('.modal__dialog');
    if (dialog.length) {
        return;
    }

    const modalId = $(this).data('modal');
    handlers.modal(this, 'close;' + modalId);
});

$(function() {
    $('[data-modal-open-on-load="1"]').each(function() {
        const modalId = $(this).data('modal');
        handlers.modal(this, 'open;' + modalId);
    });
});