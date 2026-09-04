import $ from 'jquery';
import '../../css/pages/home-venue-flow-fixes.css';

const venueFlows = new WeakSet();
const flow = document.querySelector('[data-home-flow="venue"]');
const source = document.querySelector('[data-home-venue-selector-source]');
const sharedSelector = source?.querySelector(':scope > [data-venue-selector]');
let sharedStage = null;

// Mount the real shared venue selector before home-flow initializes. This lets
// the existing venue wizard keep owning step visibility and catalog-link state.
if (flow && sharedSelector) {
    const searchPanel = flow.querySelector('[data-home-flow-panel="search"]');
    const legacySearchField = searchPanel?.querySelector('.home-flow-field');

    if (legacySearchField) {
        sharedStage = document.createElement('div');
        sharedStage.className = 'home-flow-field home-flow-venue-selector-stage';
        legacySearchField.replaceWith(sharedStage);
        sharedStage.append(sharedSelector);
    }
}

function refineVenueFlow(currentFlow) {
    if (!currentFlow || currentFlow.dataset.homeFlow !== 'venue') {
        return;
    }

    const searchPanel = currentFlow.querySelector('[data-home-flow-panel="search"]');
    const typeButtons = [...(searchPanel?.querySelectorAll('[data-home-venue-type]') || [])];
    const continueButton = searchPanel?.querySelector('.home-flow-venue-type-stage .home-flow-modal__actions .btn');
    const controlsRow = searchPanel?.querySelector('.home-flow-row');
    const paymentInputs = [...(searchPanel?.querySelectorAll('.home-flow-venue-payment .form-toggle__input') || [])];
    const venueInput = sharedSelector?.querySelector('[data-venue-selector-input]');
    const venueValue = sharedSelector?.querySelector('[data-venue-selector-value]');
    const venueClear = sharedSelector?.querySelector('[data-venue-selector-clear]');

    if (!searchPanel || typeButtons.length === 0 || !continueButton || !sharedSelector) {
        return;
    }

    if (!venueFlows.has(currentFlow)) {
        typeButtons.forEach((button) => {
            button.addEventListener('click', () => {
                // The original home-flow handler stores the selected type first.
                // Reuse its existing transition handler, but without a separate CTA.
                continueButton.click();
                window.setTimeout(() => syncSharedFilters(true), 0);
            });
        });

        paymentInputs.forEach((input) => {
            input.addEventListener('change', () => {
                window.setTimeout(() => syncSharedFilters(true), 0);
            });
        });

        venueFlows.add(currentFlow);
    }

    // home-flow already captured this element reference during initialization;
    // dropping the generic class now prevents its field CSS from restyling the
    // nested shared selector while step visibility continues to work.
    sharedStage?.classList.remove('home-flow-field');

    // The shared selector owns the map and metro controls, so the old draft row
    // ("Метро" / "Свойства") is no longer needed.
    controlsRow?.remove();
    continueButton.closest('.home-flow-modal__actions')?.remove();

    resetSharedSelection();
    syncSharedFilters(false);

    function currentType() {
        return typeButtons.find((button) => button.classList.contains('is-selected'))?.dataset.homeVenueType || 'any';
    }

    function syncSharedFilters(clearSelection) {
        const type = currentType();
        const paid = paymentInputs[0]?.checked !== false;
        const free = paymentInputs[1]?.checked !== false;
        const payment = paid && !free ? '1' : (!paid && free ? '0' : '');
        const nextType = type === 'any' ? '' : type;
        const changed = sharedSelector.dataset.venueTypeFilter !== nextType
            || sharedSelector.dataset.requiresPaymentFilter !== payment;

        if (nextType) {
            sharedSelector.dataset.venueTypeFilter = nextType;
        } else {
            delete sharedSelector.dataset.venueTypeFilter;
        }

        if (payment) {
            sharedSelector.dataset.requiresPaymentFilter = payment;
        } else {
            delete sharedSelector.dataset.requiresPaymentFilter;
        }

        if (clearSelection && changed) {
            resetSharedSelection();
        }
    }

    function resetSharedSelection() {
        if (venueInput?.value || venueValue?.value) {
            venueClear?.click();
        }
    }
}

$(document).on('modal:opened', function (_event, modal) {
    refineVenueFlow(modal.find('[data-home-flow="venue"]').get(0));
});
