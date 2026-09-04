import $ from 'jquery';

const flow = document.querySelector('[data-home-flow="event"]');
const source = document.querySelector('[data-home-event-venue-selector-source]');
const sharedSelector = source?.querySelector(':scope > [data-venue-selector]');

if (flow && sharedSelector) {
    const searchPanel = flow.querySelector('[data-home-flow-panel="search"]');
    const legacyLocationField = searchPanel?.querySelector('.home-flow-field');
    const dateRow = searchPanel?.querySelector('.home-flow-row');

    if (searchPanel && legacyLocationField && dateRow) {
        const stage = document.createElement('div');
        stage.className = 'home-flow-field home-flow-event-venue-stage';
        legacyLocationField.replaceWith(stage);
        stage.append(sharedSelector);

        // The shared selector now owns metro filtering, so the old standalone
        // "Метро" button from the home draft is no longer needed.
        dateRow.querySelector('.home-flow-filter')?.remove();

        const venueInput = sharedSelector.querySelector('[data-venue-selector-input]');
        const venueValue = sharedSelector.querySelector('[data-venue-selector-value]');
        const venueClear = sharedSelector.querySelector('[data-venue-selector-clear]');
        const metroSelect = sharedSelector.querySelector('[data-venue-selector-metro-filter]');

        venueInput?.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' && !venueValue?.value) {
                // On this step Enter is not a free-form submit: a venue must be
                // chosen from predictive results, from the map, or skipped.
                event.stopImmediatePropagation();
            }
        }, { capture: true });

        venueValue?.addEventListener('change', () => {
            if (!venueValue.value || !venueInput) {
                return;
            }

            // home-flow already owns the step state. Reuse its Enter transition
            // after the shared selector has committed a real venue.
            window.setTimeout(() => {
                venueInput.dispatchEvent(new KeyboardEvent('keydown', {
                    key: 'Enter',
                    code: 'Enter',
                    bubbles: true,
                    cancelable: true,
                }));
            }, 0);
        });

        flow.addEventListener('click', (event) => {
            const typeChoice = event.target.closest('.home-flow-type-grid button');
            const backToType = event.target.closest('[data-home-flow-step="0"], [data-home-flow-summary-step="0"]');
            if (!typeChoice && !backToType) {
                return;
            }

            window.setTimeout(resetVenueSelection, 0);
        });

        $(document).on('modal:opened.homeEventVenueBridge', function (_event, modal) {
            if (!modal.find('[data-home-flow="event"]').length) {
                return;
            }

            // home-flow needs this class only while it discovers the location
            // stage on first open. Afterwards the stored element reference is
            // enough; removing it prevents generic home-flow field CSS from
            // restyling the nested shared venue selector.
            stage.classList.remove('home-flow-field');

            // The old draft had a separate Continue button. The shared venue
            // selector advances automatically after a real venue is selected.
            modal.find('.home-flow-location-actions .home-flow-next').remove();

            // "Any location" is a skip action, not a secondary CTA.
            const skip = modal.find('.home-flow-location-any');
            skip.removeClass('btn btn--secondary').addClass('home-text-link');
            skip.find('i').remove();
            skip.find('span').text('Пропустить');
        });

        function resetVenueSelection() {
            if (venueInput?.value || venueValue?.value) {
                venueClear?.click();
            }

            if (metroSelect) {
                if (metroSelect.tomselect) {
                    metroSelect.tomselect.clear(true);
                } else {
                    Array.from(metroSelect.options).forEach((option) => {
                        option.selected = false;
                    });
                }
                metroSelect.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
    }
}
