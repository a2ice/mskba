import $ from 'jquery';
import '../../css/pages/home-venue-flow-fixes.css';

const venueFlows = new WeakSet();

function refineVenueFlow(flow) {
    if (!flow || flow.dataset.homeFlow !== 'venue' || venueFlows.has(flow)) {
        return;
    }

    const searchPanel = flow.querySelector('[data-home-flow-panel="search"]');
    const typeButtons = [...(searchPanel?.querySelectorAll('[data-home-venue-type]') || [])];
    const continueButton = searchPanel?.querySelector('.home-flow-venue-type-stage .home-flow-modal__actions .btn');

    if (!searchPanel || typeButtons.length === 0 || !continueButton) {
        return;
    }

    typeButtons.forEach((button) => {
        button.addEventListener('click', () => {
            // The original home-flow handler stores the selected type first.
            // Reuse its existing transition handler, but without a separate CTA.
            continueButton.click();
        });
    });

    continueButton.closest('.home-flow-modal__actions')?.remove();
    venueFlows.add(flow);
}

$(document).on('modal:opened', function (_event, modal) {
    refineVenueFlow(modal.find('[data-home-flow="venue"]').get(0));
});
