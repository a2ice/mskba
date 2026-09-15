(() => {
    const root = document.querySelector('[data-acquisition-onboarding]');

    if (!root) {
        return;
    }

    const form = root.querySelector('[data-acquisition-form]');
    const personaInputs = Array.from(root.querySelectorAll('input[name="onboarding_persona"]'));
    const allSteps = Array.from(root.querySelectorAll('[data-acquisition-step]'));
    const backButton = root.querySelector('[data-acquisition-back]');
    const nextButton = root.querySelector('[data-acquisition-next]');
    const submitButton = root.querySelector('[data-acquisition-submit]');
    const authenticatedContinue = root.querySelector('[data-acquisition-authenticated-continue]');
    const roleInput = root.querySelector('[data-acquisition-role]');
    const profileRequiredInputs = Array.from(root.querySelectorAll('[data-acquisition-profile-required]'));
    const progressCurrent = root.querySelector('[data-acquisition-progress-current]');
    const progressCaption = root.querySelector('[data-acquisition-progress-caption]');
    const progressBar = root.querySelector('[data-acquisition-progress-bar]');
    const loginPanel = root.querySelector('[data-acquisition-login-panel]');
    const showLoginButton = root.querySelector('[data-acquisition-show-login]');
    const hideLoginButton = root.querySelector('[data-acquisition-hide-login]');
    const locationButton = root.querySelector('[data-acquisition-location-button]');
    const locationStatus = root.querySelector('[data-acquisition-location-status]');
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const authenticated = root.dataset.authenticated === '1';
    const hasLocationTarget = root.dataset.hasLocationTarget === '1';
    const hasRegistrationErrors = Boolean(root.querySelector('.acquisition-onboarding__errors'));
    let currentIndex = 0;

    const personaRoleMap = {
        player: 'player',
        coach: 'coach',
        venue: 'venue_related',
        organizer: '',
        explore: '',
    };

    const captions = {
        persona: 'Выберите свой сценарий',
        account: 'Создайте аккаунт',
        profile: 'Заполните базовый профиль',
    };

    function selectedPersona() {
        return personaInputs.find((input) => input.checked)?.value || '';
    }

    function personaNeedsProfile(persona = selectedPersona()) {
        return persona === 'player' || persona === 'coach';
    }

    function visibleSteps() {
        if (authenticated) {
            return allSteps.filter((step) => step.dataset.acquisitionStep === 'persona');
        }

        return allSteps.filter((step) => {
            if (step.dataset.acquisitionStep !== 'profile') {
                return true;
            }

            return personaNeedsProfile();
        });
    }

    function syncPersonaUi() {
        const persona = selectedPersona();

        personaInputs.forEach((input) => {
            input.closest('.acquisition-persona-card')?.classList.toggle('is-selected', input.checked);
        });

        if (roleInput) {
            roleInput.value = personaRoleMap[persona] ?? '';
        }

        profileRequiredInputs.forEach((input) => {
            input.required = personaNeedsProfile(persona);
        });
    }

    function syncControls() {
        const steps = visibleSteps();
        const step = steps[currentIndex];
        const isFirst = currentIndex === 0;
        const isLast = currentIndex === steps.length - 1;
        const persona = selectedPersona();

        if (backButton) {
            backButton.hidden = isFirst;
        }

        if (nextButton) {
            nextButton.hidden = isLast;
            nextButton.disabled = step?.dataset.acquisitionStep === 'persona' && !persona;
        }

        if (submitButton) {
            submitButton.hidden = authenticated || !isLast;
        }

        if (authenticatedContinue) {
            authenticatedContinue.hidden = !authenticated || !isLast;
            authenticatedContinue.disabled = !persona;
        }
    }

    function showStep(index) {
        const steps = visibleSteps();

        if (!steps.length) {
            return;
        }

        currentIndex = Math.max(0, Math.min(index, steps.length - 1));
        allSteps.forEach((step) => {
            step.hidden = true;
        });

        const currentStep = steps[currentIndex];
        currentStep.hidden = false;
        const stepKey = currentStep.dataset.acquisitionStep || 'persona';

        if (progressCurrent) {
            progressCurrent.textContent = String(currentIndex + 1);
        }

        if (progressCaption) {
            progressCaption.textContent = captions[stepKey] || '';
        }

        if (progressBar) {
            progressBar.style.width = `${((currentIndex + 1) / steps.length) * 100}%`;
        }

        syncControls();
    }

    function currentStepIsValid() {
        const steps = visibleSteps();
        const step = steps[currentIndex];

        if (!step) {
            return true;
        }

        if (step.dataset.acquisitionStep === 'persona' && !selectedPersona()) {
            personaInputs[0]?.focus();
            return false;
        }

        const fields = Array.from(step.querySelectorAll('input, select, textarea'))
            .filter((field) => !field.disabled && field.type !== 'hidden');

        for (const field of fields) {
            if (!field.checkValidity()) {
                field.reportValidity();
                return false;
            }
        }

        return true;
    }

    async function postJson(url, payload) {
        if (!url) {
            return null;
        }

        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
            },
            body: JSON.stringify(payload),
        });

        if (!response.ok) {
            throw new Error(`Request failed with status ${response.status}`);
        }

        return response.json();
    }

    async function persistPersona() {
        const persona = selectedPersona();

        if (!persona) {
            return null;
        }

        try {
            return await postJson(root.dataset.personaUrl, { persona });
        } catch (error) {
            console.warn('MSKBA acquisition persona was not persisted.', error);
            return null;
        }
    }

    function updateLocationStatus(message, variant = '') {
        if (!locationStatus) {
            return;
        }

        locationStatus.textContent = message;
        locationStatus.classList.remove('is-success', 'is-warning');

        if (variant) {
            locationStatus.classList.add(`is-${variant}`);
        }
    }

    async function persistLocation(status, position = null) {
        try {
            const result = await postJson(root.dataset.locationUrl, {
                status,
                latitude: position?.coords?.latitude ?? null,
                longitude: position?.coords?.longitude ?? null,
                accuracy: position?.coords?.accuracy ?? null,
            });

            if (!result) {
                return;
            }

            if (result.status === 'verified') {
                updateLocationStatus(
                    result.distance_meters !== null
                        ? `Положение подтверждено · около ${result.distance_meters} м от площадки.`
                        : 'Положение подтверждено.',
                    'success',
                );
                return;
            }

            if (result.status === 'mismatch') {
                updateLocationStatus(
                    result.distance_meters !== null
                        ? `Сейчас вы примерно в ${result.distance_meters} м от площадки. Переход всё равно сохранён.`
                        : 'Текущее положение не совпало с площадкой. Переход всё равно сохранён.',
                    'warning',
                );
                return;
            }

            if (result.status === 'inaccurate') {
                updateLocationStatus('Геопозиция получена с низкой точностью. Переход сохранён без подтверждения площадки.', 'warning');
                return;
            }

            if (result.status === 'denied') {
                updateLocationStatus('Доступ к геопозиции не разрешён. Это не мешает регистрации.', 'warning');
                return;
            }

            updateLocationStatus('Не удалось проверить положение. Это не мешает регистрации.', 'warning');
        } catch (error) {
            updateLocationStatus('Не удалось сохранить проверку положения. Регистрацию можно продолжить.', 'warning');
            console.warn('MSKBA acquisition location was not persisted.', error);
        }
    }

    function requestLocation() {
        if (!hasLocationTarget || !locationButton) {
            return;
        }

        if (!navigator.geolocation) {
            updateLocationStatus('Этот браузер не поддерживает геопозицию. Это не мешает регистрации.', 'warning');
            void persistLocation('unavailable');
            return;
        }

        locationButton.disabled = true;
        updateLocationStatus('Проверяем положение…');

        navigator.geolocation.getCurrentPosition(
            async (position) => {
                await persistLocation('granted', position);
                locationButton.disabled = false;
            },
            async (error) => {
                await persistLocation(error.code === error.PERMISSION_DENIED ? 'denied' : 'unavailable');
                locationButton.disabled = false;
            },
            {
                enableHighAccuracy: true,
                timeout: 8000,
                maximumAge: 0,
            },
        );
    }

    personaInputs.forEach((input) => {
        input.addEventListener('change', () => {
            syncPersonaUi();
            const steps = visibleSteps();

            if (currentIndex >= steps.length) {
                currentIndex = steps.length - 1;
            }

            showStep(currentIndex);
            void persistPersona();
        });
    });

    nextButton?.addEventListener('click', async () => {
        if (!currentStepIsValid()) {
            return;
        }

        await persistPersona();
        showStep(currentIndex + 1);
        root.querySelector('[data-acquisition-step]:not([hidden])')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });

    backButton?.addEventListener('click', () => {
        showStep(currentIndex - 1);
    });

    form?.addEventListener('submit', (event) => {
        syncPersonaUi();

        if (!currentStepIsValid()) {
            event.preventDefault();
        }
    });

    authenticatedContinue?.addEventListener('click', async () => {
        if (!currentStepIsValid()) {
            return;
        }

        authenticatedContinue.disabled = true;
        await persistPersona();
        window.location.assign(root.dataset.successUrl || '/join/success');
    });

    showLoginButton?.addEventListener('click', () => {
        if (!loginPanel) {
            return;
        }

        loginPanel.hidden = false;
        loginPanel.scrollIntoView({ behavior: 'smooth', block: 'center' });
        loginPanel.querySelector('input[name="login"]')?.focus({ preventScroll: true });
    });

    hideLoginButton?.addEventListener('click', () => {
        if (loginPanel) {
            loginPanel.hidden = true;
        }
    });

    locationButton?.addEventListener('click', requestLocation);

    async function maybeVerifyAlreadyGrantedLocation() {
        if (!hasLocationTarget || !navigator.permissions || !navigator.geolocation) {
            return;
        }

        try {
            const permission = await navigator.permissions.query({ name: 'geolocation' });

            if (permission.state === 'granted') {
                requestLocation();
            } else if (permission.state === 'denied') {
                await persistLocation('denied');
            }
        } catch (_) {
            // Safari and some embedded browsers do not expose geolocation through Permissions API.
        }
    }

    syncPersonaUi();

    if (hasRegistrationErrors && selectedPersona() && !authenticated) {
        currentIndex = 1;
    }

    showStep(currentIndex);
    void maybeVerifyAlreadyGrantedLocation();
})();
