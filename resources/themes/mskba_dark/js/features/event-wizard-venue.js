document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-event-wizard]').forEach((form) => {
        const selector = form.querySelector('[data-venue-selector]');
        const valueInput = selector?.querySelector('[data-venue-selector-value]');
        const list = selector?.querySelector('[data-venue-selector-list]');
        const scopeInput = selector?.querySelector('[data-venue-booking-scope-input]');
        if (!selector || !valueInput || !scopeInput) return;

        const standardSearchUrl = selector.dataset.searchUrl;
        const flexibleSearchUrl = `${window.location.pathname.replace(/\/$/, '')}/venues`;
        const typeRadios = Array.from(form.querySelectorAll('[data-wizard-type]'));
        const formatRadios = Array.from(form.querySelectorAll('[data-game-format]'));
        const scoringInput = form.querySelector('[data-game-scoring]');
        const sideAInput = form.querySelector('[data-game-side-a]');
        const sideBInput = form.querySelector('[data-game-side-b]');
        const customInputs = Array.from(form.querySelectorAll(
            '[data-custom-side-a], [data-custom-side-b], [data-custom-scoring], [data-custom-timing]',
        ));
        let flexible = false;

        const selected = (items) => items.find((item) => item.checked)?.value || '';
        const isHalfCourtCompatible = () => {
            if (selected(typeRadios) !== 'game') return false;
            const format = selected(formatRadios);
            if (format === 'streetball_3x3' || format === 'streetball_1x1') return true;
            if (format !== 'custom') return false;

            return scoringInput?.value === 'streetball'
                && Math.max(Number(sideAInput?.value || 0), Number(sideBInput?.value || 0)) <= 3;
        };

        const applySearchMode = (revalidate = true) => {
            const nextFlexible = isHalfCourtCompatible();
            selector.dataset.searchUrl = nextFlexible ? flexibleSearchUrl : standardSearchUrl;

            if (!nextFlexible && scopeInput.value !== 'whole') {
                scopeInput.value = 'whole';
            }

            if (revalidate && flexible !== nextFlexible && valueInput.value) {
                scopeInput.dispatchEvent(new Event('change', { bubbles: true }));
            }
            flexible = nextFlexible;
        };

        const chooseAvailableScopeFromSearchResult = () => {
            if (!flexible || !valueInput.value || !list?.dataset.venues) return;

            let venues = [];
            try {
                venues = JSON.parse(list.dataset.venues || '[]');
            } catch (_) {
                return;
            }
            const venue = venues.find((item) => Number(item.id) === Number(valueInput.value));
            const scopes = Array.isArray(venue?.available_scopes) ? venue.available_scopes : [];
            if (!scopes.length || scopes.includes(scopeInput.value)) return;

            const preferred = ['whole', 'half_a', 'half_b'].find((scope) => scopes.includes(scope));
            if (!preferred) return;
            scopeInput.value = preferred;
            scopeInput.dispatchEvent(new Event('change', { bubbles: true }));
        };

        typeRadios.forEach((input) => input.addEventListener('change', () => applySearchMode()));
        formatRadios.forEach((input) => input.addEventListener('change', () => applySearchMode()));
        customInputs.forEach((input) => input.addEventListener('change', () => applySearchMode()));
        valueInput.addEventListener('change', chooseAvailableScopeFromSearchResult);

        applySearchMode(false);
    });
});
