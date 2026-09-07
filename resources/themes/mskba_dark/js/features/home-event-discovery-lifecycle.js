import $ from 'jquery';
import '../../css/pages/home-event-results-progress.css';

$(document).on('modal:closed.homeEventDiscoveryLifecycle', function (_event, modal) {
    const flow = modal.find('[data-home-flow="event"]').get(0);
    const resultsStage = flow?.querySelector('[data-home-event-results-stage]');

    if (!flow || !resultsStage || resultsStage.hidden) {
        return;
    }

    // The discovery layer owns its own final state while the underlying V2
    // wizard remains on the date step. Reuse its intercepted Back action while
    // the modal is already hidden so the request is aborted and the closure
    // state is reset before the next open.
    const back = flow.querySelector('[data-home-flow-wizard-back]');
    if (back) {
        back.disabled = false;
        back.click();
    }
});
