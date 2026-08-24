document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-event-wizard]').forEach((form) => {
        if (!(form instanceof HTMLFormElement)) return;

        const parameters = new URLSearchParams(window.location.search);
        const presetVenueId = Number(parameters.get('venue_id') || 0);
        const venueValue = form.querySelector('[data-venue-selector-value]');
        if (presetVenueId > 0 && venueValue instanceof HTMLInputElement && !venueValue.value) {
            venueValue.value = String(presetVenueId);
            form.dataset.presetVenueId = String(presetVenueId);
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
