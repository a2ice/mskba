document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-event-wizard]').forEach(initWizardVenueAvailability);
});

function initWizardVenueAvailability(form) {
    const selector = form.querySelector('[data-venue-selector]');
    const valueInput = selector?.querySelector('[data-venue-selector-value]');
    const textInput = selector?.querySelector('[data-venue-selector-input]');
    const clearButton = selector?.querySelector('[data-venue-selector-clear]');
    const list = selector?.querySelector('[data-venue-selector-list]');
    const scopeInput = selector?.querySelector('[data-venue-booking-scope-input]');
    const previewOpen = selector?.querySelector('[data-venue-preview-open]');
    if (!selector || !valueInput || !textInput || !scopeInput) return;

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
    const startInput = selector.dataset.startInput
        ? document.querySelector(selector.dataset.startInput)
        : null;
    const durationInput = selector.dataset.durationInput
        ? document.querySelector(selector.dataset.durationInput)
        : null;
    let flexible = false;
    let scopeController = null;
    let hydrationController = null;

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

    const parsedListVenues = () => {
        if (!list?.dataset.venues) return [];
        try {
            const venues = JSON.parse(list.dataset.venues || '[]');
            return Array.isArray(venues) ? venues : [];
        } catch (_) {
            return [];
        }
    };

    const applyAvailableScopes = (venue, notify = false) => {
        if (!flexible || !venue || Number(venue.id) !== Number(valueInput.value)) return false;
        const scopes = Array.isArray(venue.available_scopes) ? venue.available_scopes : [];
        if (!scopes.length) return false;

        const preferred = scopes.includes(scopeInput.value)
            ? scopeInput.value
            : ['whole', 'half_a', 'half_b'].find((scope) => scopes.includes(scope));
        if (!preferred) return false;

        const changed = scopeInput.value !== preferred;
        scopeInput.value = preferred;
        Array.from(scopeInput.options).forEach((option) => {
            option.disabled = !scopes.includes(option.value);
            option.textContent = `${scopeLabel(option.value)}${scopes.includes(option.value) ? '' : ' — занято'}`;
        });

        if (notify && changed) {
            scopeInput.dispatchEvent(new Event('change', { bubbles: true }));
        }
        return true;
    };

    const selectedVenueFromList = () => parsedListVenues()
        .find((item) => Number(item.id) === Number(valueInput.value));

    const chooseAvailableScopeFromSearchResult = () => {
        if (!flexible || !valueInput.value) return;
        const venue = selectedVenueFromList();
        if (!venue) return;

        if (!applyAvailableScopes(venue, false)) {
            resolveAvailableScopes(venue);
        }
    };

    async function resolveAvailableScopes(venue) {
        if (!flexible || !venue?.id || !startInput?.value || !durationInput?.value) return;
        scopeController?.abort();
        const controller = new AbortController();
        scopeController = controller;

        const parameters = new URLSearchParams({
            query: '',
            venue_id: String(venue.id),
            discover_scopes: '1',
            confirmed_only: selector.dataset.confirmedOnly || '0',
            starts_at: startInput.value,
            duration_minutes: durationInput.value,
            booking_scope: scopeInput.value || 'whole',
            limit: '1',
        });
        if (selector.dataset.operationalStatus) {
            parameters.set('operational_status', selector.dataset.operationalStatus);
        }

        try {
            const response = await fetch(`${flexibleSearchUrl}?${parameters.toString()}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: controller.signal,
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) return;
            const resolved = Array.isArray(payload.venues)
                ? payload.venues.find((item) => Number(item.id) === Number(venue.id))
                : null;
            if (resolved) {
                applyAvailableScopes(resolved, true);
            }
        } catch (error) {
            if (error.name !== 'AbortError') {
                // Exact server-side validation still runs before creation.
            }
        } finally {
            if (scopeController === controller) scopeController = null;
        }
    }

    async function hydrateInitialVenue() {
        const venueId = Number(valueInput.value || 0);
        if (!venueId || textInput.value.trim()) return;
        hydrationController?.abort();
        const controller = new AbortController();
        hydrationController = controller;

        const parameters = new URLSearchParams({
            query: '',
            venue_id: String(venueId),
            confirmed_only: selector.dataset.confirmedOnly || '0',
            limit: '1',
        });
        if (selector.dataset.operationalStatus) {
            parameters.set('operational_status', selector.dataset.operationalStatus);
        }

        try {
            const response = await fetch(`${standardSearchUrl}?${parameters.toString()}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: controller.signal,
            });
            const payload = await response.json().catch(() => ({}));
            const venue = response.ok && Array.isArray(payload.venues)
                ? payload.venues.find((item) => Number(item.id) === venueId)
                : null;

            if (!venue) {
                valueInput.value = '';
                textInput.value = '';
                textInput.dispatchEvent(new Event('input', { bubbles: true }));
                return;
            }

            const address = displayAddress(venue.address);
            textInput.value = `${venue.name}${address ? ` — ${address}` : ''}`;
            selector.dataset.selectedHoopsCount = String(venue.hoops_count || 1);
            if (clearButton) clearButton.hidden = false;
            if (previewOpen) {
                previewOpen.dataset.previewUrl = venue.preview_url || '';
                previewOpen.hidden = !venue.preview_url;
            }

            if (flexible) {
                await resolveAvailableScopes(venue);
            } else {
                scopeInput.dispatchEvent(new Event('change', { bubbles: true }));
            }
        } catch (error) {
            if (error.name !== 'AbortError') {
                textInput.setCustomValidity('Не удалось восстановить выбранную площадку. Выберите её повторно.');
            }
        } finally {
            if (hydrationController === controller) hydrationController = null;
        }
    }

    typeRadios.forEach((input) => input.addEventListener('change', () => applySearchMode()));
    formatRadios.forEach((input) => input.addEventListener('change', () => applySearchMode()));
    customInputs.forEach((input) => input.addEventListener('change', () => applySearchMode()));
    valueInput.addEventListener('change', chooseAvailableScopeFromSearchResult);
    [startInput, durationInput].filter(Boolean).forEach((input) => input.addEventListener('change', () => {
        const venue = selectedVenueFromList();
        if (flexible && venue) resolveAvailableScopes(venue);
    }));

    applySearchMode(false);
    window.setTimeout(hydrateInitialVenue, 0);
}

function scopeLabel(scope) {
    return {
        whole: 'Вся площадка',
        half_a: 'Половина A',
        half_b: 'Половина B',
    }[scope] || scope;
}

function displayAddress(address) {
    return String(address || '')
        .replace(/^(?:Россия|Российская Федерация)\s*,\s*/iu, '')
        .trim();
}
