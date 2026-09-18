document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('[data-admin-acquisition-form]');
    if (!(form instanceof HTMLFormElement)) return;

    const channel = form.querySelector('[data-acquisition-channel]');
    const landingType = form.querySelector('[data-acquisition-landing-type]');
    const physical = form.querySelector('[data-acquisition-physical-context]');
    const locationCapability = form.querySelector('[data-acquisition-location-capability]');
    const locationToggle = form.querySelector('[data-acquisition-location-toggle]');
    const radius = form.querySelector('[data-acquisition-radius]');
    const landingTarget = form.querySelector('[data-acquisition-landing-target]');
    const landingPredictive = landingTarget?.querySelector('[data-entity-predictive-search]');
    const landingInput = landingTarget?.querySelector('[data-entity-predictive-input]');
    const landingValue = landingTarget?.querySelector('[data-entity-predictive-value]');
    const landingClear = landingTarget?.querySelector('[data-entity-predictive-clear]');
    const landingMessage = landingTarget?.querySelector('[data-entity-predictive-message]');
    const qrMaterials = document.querySelector('[data-acquisition-qr-materials]');

    const setGroupEnabled = (root, enabled) => {
        if (!(root instanceof HTMLElement)) return;
        root.hidden = !enabled;
        root.querySelectorAll('input, select, textarea, button').forEach((control) => {
            if (control.matches('[data-keep-enabled]')) return;
            control.disabled = !enabled;
        });
    };

    const clearLandingTarget = () => {
        if (landingInput instanceof HTMLInputElement) landingInput.value = '';
        if (landingValue instanceof HTMLInputElement) {
            landingValue.value = '';
            landingValue.dispatchEvent(new Event('change', { bubbles: true }));
        }
        if (landingClear instanceof HTMLElement) landingClear.hidden = true;
        if (landingMessage instanceof HTMLElement) {
            landingMessage.textContent = 'Введите не менее 2 символов и выберите вариант.';
        }
    };

    const syncChannel = () => {
        if (!(channel instanceof HTMLSelectElement)) return;
        const option = channel.selectedOptions[0];
        const supportsPhysical = option?.dataset.supportsPhysical === '1';
        const supportsLocation = option?.dataset.supportsLocation === '1';
        const supportsMaterials = option?.dataset.supportsMaterials === '1';

        setGroupEnabled(physical, supportsPhysical);
        setGroupEnabled(locationCapability, supportsPhysical && supportsLocation);

        if (!supportsLocation && locationToggle instanceof HTMLInputElement) {
            locationToggle.checked = false;
        }

        syncRadius();
        if (qrMaterials instanceof HTMLElement) qrMaterials.hidden = !supportsMaterials;
    };

    const syncRadius = () => {
        const enabled = locationToggle instanceof HTMLInputElement && locationToggle.checked && !locationToggle.disabled;
        setGroupEnabled(radius, enabled);
    };

    const syncLanding = (clearTarget = false) => {
        if (!(landingType instanceof HTMLSelectElement) || !(landingTarget instanceof HTMLElement)) return;
        const option = landingType.selectedOptions[0];
        const needsTarget = option?.dataset.needsTarget === '1';
        const type = landingType.value;

        setGroupEnabled(landingTarget, needsTarget);

        if (landingPredictive instanceof HTMLElement) {
            const baseUrl = landingPredictive.dataset.searchBaseUrl || '';
            if (baseUrl && needsTarget) {
                const url = new URL(baseUrl, window.location.origin);
                url.searchParams.set('type', type);
                landingPredictive.dataset.searchUrl = url.toString();
            } else {
                landingPredictive.dataset.searchUrl = '';
            }
        }

        if (clearTarget) clearLandingTarget();
    };

    channel?.addEventListener('change', syncChannel);
    locationToggle?.addEventListener('change', syncRadius);
    landingType?.addEventListener('change', () => syncLanding(true));

    syncChannel();
    syncLanding(false);
});
