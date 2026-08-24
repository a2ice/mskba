document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-event-wizard]').forEach((form) => {
        if (!(form instanceof HTMLFormElement)) return;

        const parameters = new URLSearchParams(window.location.search);
        const presetVenueId = Number(parameters.get('venue_id') || 0);
        const venueSelector = form.querySelector('[data-venue-selector]');
        const venueValue = venueSelector?.querySelector('[data-venue-selector-value]');
        if (presetVenueId > 0 && venueValue instanceof HTMLInputElement && !venueValue.value) {
            venueValue.value = String(presetVenueId);
            form.dataset.presetVenueId = String(presetVenueId);
        }

        if (venueSelector && venueValue instanceof HTMLInputElement) {
            const notifyVenueMetadataChanged = () => {
                if (!venueValue.value) return;
                venueValue.dispatchEvent(new Event('change', { bubbles: true }));
            };

            const metadataObserver = new MutationObserver((mutations) => {
                if (mutations.some((mutation) => mutation.attributeName === 'data-selected-hoops-count')) {
                    notifyVenueMetadataChanged();
                }
            });
            metadataObserver.observe(venueSelector, {
                attributes: true,
                attributeFilter: ['data-selected-hoops-count'],
            });
        }

        const hasServerErrors = Boolean(
            form.closest('.event-wizard-page')?.querySelector('.alert.alert-danger')
            || form.querySelector('.invalid-feedback.d-block'),
        );
        if (hasServerErrors) return;

        const start = form.querySelector('[data-wizard-start]');
        if (!(start instanceof HTMLInputElement) || !start.value) return;

        start.dataset.venueFirstDefault = start.value;
        start.value = '';
        form.dataset.venueFirstFresh = '1';
    });
});
