(() => {
    const root = document.querySelector('[data-acquisition-onboarding]');

    if (!root) {
        return;
    }

    const form = root.querySelector('[data-acquisition-form]');
    const personaInputs = Array.from(root.querySelectorAll('input[name="onboarding_persona"]'));
    const allSteps = Array.from(root.querySelectorAll('[data-acquisition-step]'));
    const footer = root.querySelector('[data-acquisition-footer]');
    const backButton = root.querySelector('[data-acquisition-back]');
    const nextButton = root.querySelector('[data-acquisition-next]');
    const submitButton = root.querySelector('[data-acquisition-submit]');
    const startJoinButton = root.querySelector('[data-acquisition-start-join]');
    const startLoginButton = root.querySelector('[data-acquisition-start-login]');
    const roleInput = root.querySelector('[data-acquisition-role]');
    const profileRequiredInputs = Array.from(root.querySelectorAll('[data-acquisition-profile-required]'));
    const progressCurrent = root.querySelector('[data-acquisition-progress-current]');
    const progressCaption = root.querySelector('[data-acquisition-progress-caption]');
    const progressBar = root.querySelector('[data-acquisition-progress-bar]');
    const locationStatus = root.querySelector('[data-acquisition-location-status]');
    const authRolesForm = root.querySelector('[data-acquisition-auth-roles-form]');
    const authRoleToggles = Array.from(root.querySelectorAll('[data-acquisition-role-toggle]'));
    const authRoleSummary = root.querySelector('[data-acquisition-role-summary]');
    const authRolesStatus = root.querySelector('[data-acquisition-roles-status]');
    const saveRolesButton = root.querySelector('[data-acquisition-save-roles]');
    const authContinueButton = root.querySelector('[data-acquisition-auth-continue]');
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const hasLocationTarget = root.dataset.hasLocationTarget === '1';
    const isAuthenticated = root.dataset.authenticated === '1';

    let flow = isAuthenticated ? 'authenticated' : (root.dataset.initialFlow || '');
    let currentStepKey = 'entry';
    let locationRequestStarted = false;

    const personaRoleMap = {
        player: 'player',
        coach: 'coach',
        venue: 'venue_related',
        organizer: 'organizer',
        explore: '',
    };

    const captions = {
        entry: isAuthenticated ? 'Твои роли' : 'Быстрая регистрация',
        login: 'Вход в аккаунт',
        persona: 'Выбор роли',
        account: 'Создание аккаунта',
        profile: 'Базовый профиль',
    };

    function selectedPersona() {
        return personaInputs.find((input) => input.checked)?.value || '';
    }

    function personaNeedsProfile(persona = selectedPersona()) {
        return persona === 'player' || persona === 'coach';
    }

    function flowSteps() {
        if (flow === 'authenticated') {
            return ['entry'];
        }

        if (flow === 'login') {
            return ['entry', 'login'];
        }

        if (flow === 'join') {
            const steps = ['entry', 'persona', 'account'];

            if (personaNeedsProfile()) {
                steps.push('profile');
            }

            return steps;
        }

        return ['entry'];
    }

    function findStep(key) {
        return allSteps.find((step) => step.dataset.acquisitionStep === key) || null;
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

    function syncProgress() {
        const steps = flowSteps();
        const currentIndex = Math.max(0, steps.indexOf(currentStepKey));
        const displayTotal = flow ? steps.length : 3;
        const displayIndex = flow ? currentIndex + 1 : 1;

        if (progressCurrent) {
            progressCurrent.textContent = String(displayIndex);
        }

        if (progressCaption) {
            progressCaption.textContent = captions[currentStepKey] || '';
        }

        if (progressBar) {
            progressBar.style.width = `${(displayIndex / displayTotal) * 100}%`;
        }
    }

    function syncControls() {
        const steps = flowSteps();
        const currentIndex = steps.indexOf(currentStepKey);
        const isEntry = currentStepKey === 'entry';
        const isLogin = currentStepKey === 'login';
        const isPersona = currentStepKey === 'persona';
        const isAccount = currentStepKey === 'account';
        const isProfile = currentStepKey === 'profile';

        if (footer) {
            footer.hidden = isEntry;
        }

        if (backButton) {
            backButton.hidden = isEntry;
        }

        if (nextButton) {
            nextButton.hidden = !(isPersona || (isAccount && personaNeedsProfile()));
            nextButton.disabled = isPersona && !selectedPersona();
        }

        if (submitButton) {
            submitButton.hidden = !(isProfile || (isAccount && !personaNeedsProfile()));
        }

        if (isLogin && nextButton) {
            nextButton.hidden = true;
        }

        if (isLogin && submitButton) {
            submitButton.hidden = true;
        }

        if (currentIndex < 0 && backButton) {
            backButton.hidden = true;
        }
    }

    function showStep(key, options = {}) {
        const step = findStep(key);

        if (!step) {
            return;
        }

        currentStepKey = key;
        allSteps.forEach((item) => {
            item.hidden = item !== step;
        });

        syncProgress();
        syncControls();

        if (options.scroll) {
            step.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        if (key === 'login' && options.focus !== false) {
            window.setTimeout(() => {
                step.querySelector('input[name="login"]')?.focus({ preventScroll: true });
            }, 0);
        }
    }

    function currentStepIsValid() {
        const step = findStep(currentStepKey);

        if (!step) {
            return true;
        }

        if (currentStepKey === 'persona' && !selectedPersona()) {
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

    async function requestJson(url, payload, method = 'POST') {
        if (!url) {
            return null;
        }

        const response = await fetch(url, {
            method,
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
            return await requestJson(root.dataset.personaUrl, { persona });
        } catch (error) {
            console.warn('MSKBA acquisition persona was not persisted.', error);
            return null;
        }
    }

    function roleKey(input) {
        return input.name.match(/^roles\[([^\]]+)]$/)?.[1] || '';
    }

    function selectedAuthRoleLabels() {
        return authRoleToggles
            .filter((input) => input.checked)
            .map((input) => input.dataset.roleLabel || '')
            .filter(Boolean);
    }

    function formatRoleSummary(labels) {
        const normalized = labels.map((label) => label.charAt(0).toLocaleLowerCase('ru-RU') + label.slice(1));

        if (normalized.length === 0) {
            return 'не установлена';
        }

        if (normalized.length === 1) {
            return normalized[0];
        }

        if (normalized.length === 2) {
            return `${normalized[0]} и ${normalized[1]}`;
        }

        return `${normalized[0]} и ещё ${normalized.length - 1}`;
    }

    function syncAuthRoleSummary(labels = selectedAuthRoleLabels()) {
        if (authRoleSummary) {
            authRoleSummary.textContent = formatRoleSummary(labels);
        }
    }

    function updateRolesStatus(message, variant = '') {
        if (!authRolesStatus) {
            return;
        }

        authRolesStatus.textContent = message;
        authRolesStatus.classList.remove('is-success', 'is-warning');

        if (variant) {
            authRolesStatus.classList.add(`is-${variant}`);
        }
    }

    async function persistAuthRoles() {
        if (!isAuthenticated || authRoleToggles.length === 0) {
            return true;
        }

        const roles = {};
        authRoleToggles.forEach((input) => {
            const key = roleKey(input);
            if (key) {
                roles[key] = input.checked;
            }
        });

        saveRolesButton && (saveRolesButton.disabled = true);
        authContinueButton && (authContinueButton.disabled = true);
        updateRolesStatus('Сохраняем…');

        try {
            const result = await requestJson(root.dataset.rolesUrl, { roles }, 'PATCH');
            const labels = Array.isArray(result?.roles) ? result.roles.map((role) => role.label) : selectedAuthRoleLabels();
            syncAuthRoleSummary(labels);
            updateRolesStatus('Роли сохранены.', 'success');
            return true;
        } catch (error) {
            updateRolesStatus('Не удалось сохранить роли. Попробуй ещё раз.', 'warning');
            console.warn('MSKBA acquisition roles were not persisted.', error);
            return false;
        } finally {
            saveRolesButton && (saveRolesButton.disabled = false);
            authContinueButton && (authContinueButton.disabled = false);
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
            const result = await requestJson(root.dataset.locationUrl, {
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
        if (!hasLocationTarget || locationRequestStarted) {
            return;
        }

        locationRequestStarted = true;

        if (!navigator.geolocation) {
            updateLocationStatus('Этот браузер не поддерживает геопозицию. Это не мешает регистрации.', 'warning');
            void persistLocation('unavailable');
            return;
        }

        updateLocationStatus('Проверяем положение…');

        navigator.geolocation.getCurrentPosition(
            async (position) => {
                await persistLocation('granted', position);
            },
            async (error) => {
                await persistLocation(error.code === error.PERMISSION_DENIED ? 'denied' : 'unavailable');
            },
            {
                enableHighAccuracy: true,
                timeout: 8000,
                maximumAge: 0,
            },
        );
    }

    startJoinButton?.addEventListener('click', () => {
        requestLocation();
        flow = 'join';
        showStep('persona', { scroll: true });
    });

    startLoginButton?.addEventListener('click', () => {
        requestLocation();
        flow = 'login';
        showStep('login', { scroll: true });
    });

    personaInputs.forEach((input) => {
        input.addEventListener('change', () => {
            syncPersonaUi();
            syncProgress();
            syncControls();
            void persistPersona();
        });
    });

    authRoleToggles.forEach((input) => {
        input.addEventListener('change', () => {
            syncAuthRoleSummary();
            updateRolesStatus('Есть несохранённые изменения.');
        });
    });

    saveRolesButton?.addEventListener('click', () => {
        void persistAuthRoles();
    });

    authRolesForm?.addEventListener('submit', (event) => {
        event.preventDefault();
        void persistAuthRoles();
    });

    authContinueButton?.addEventListener('click', async () => {
        const saved = await persistAuthRoles();

        if (saved && root.dataset.successUrl) {
            window.location.assign(root.dataset.successUrl);
        }
    });

    nextButton?.addEventListener('click', async () => {
        if (!currentStepIsValid()) {
            return;
        }

        if (currentStepKey === 'persona') {
            await persistPersona();
            showStep('account', { scroll: true });
            return;
        }

        if (currentStepKey === 'account' && personaNeedsProfile()) {
            showStep('profile', { scroll: true });
        }
    });

    backButton?.addEventListener('click', () => {
        if (currentStepKey === 'login' || currentStepKey === 'persona') {
            flow = '';
            showStep('entry', { scroll: true, focus: false });
            return;
        }

        if (currentStepKey === 'account') {
            showStep('persona', { scroll: true });
            return;
        }

        if (currentStepKey === 'profile') {
            showStep('account', { scroll: true });
        }
    });

    form?.addEventListener('submit', (event) => {
        syncPersonaUi();

        if (!selectedPersona() || !currentStepIsValid()) {
            event.preventDefault();

            if (!selectedPersona()) {
                flow = 'join';
                showStep('persona', { scroll: true });
            }
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
                locationRequestStarted = true;
                await persistLocation('denied');
            }
        } catch (_) {
            // Safari and some embedded browsers do not expose geolocation through Permissions API.
        }
    }

    syncPersonaUi();
    syncAuthRoleSummary();

    if (flow === 'authenticated') {
        showStep('entry', { focus: false });
    } else if (flow === 'login') {
        showStep('login', { focus: false });
    } else if (flow === 'join') {
        const errorStep = root.dataset.errorStep || '';

        if (errorStep === 'profile' && personaNeedsProfile()) {
            showStep('profile', { focus: false });
        } else if (errorStep === 'account') {
            showStep('account', { focus: false });
        } else {
            showStep('persona', { focus: false });
        }
    } else {
        showStep('entry', { focus: false });
    }

    void maybeVerifyAlreadyGrantedLocation();
})();
