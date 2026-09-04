import $ from 'jquery';

function activatePanel(modal, section) {
    const requested = section === 'create' ? 'create' : 'search';
    modal.find('[data-home-flow-panel]').each(function () {
        this.hidden = this.dataset.homeFlowPanel !== requested;
    });
    modal.find('[data-home-flow-tab]').each(function () {
        $(this).toggleClass('is-active', this.dataset.homeFlowTab === requested);
    });
}

$(document).on('modal:opened', function (_event, modal) {
    const flow = modal.find('[data-home-flow]');
    if (!flow.length) {
        return;
    }

    activatePanel(modal, String(modal.data('modalInitialSection') || 'search'));
    modal.removeData('modalInitialSection');
});

$(document).on('click', '[data-home-flow-tab]', function () {
    const modal = $(this).closest('[data-modal]');
    if (!modal.length) {
        return;
    }

    activatePanel(modal, String(this.dataset.homeFlowTab || 'search'));
});
