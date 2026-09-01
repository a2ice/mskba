document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-event-wizard]').forEach(initEventWizard);
});

function initEventWizard(form) {
    if (!(form instanceof HTMLFormElement) || form.dataset.ready === '1') return;
    form.dataset.ready = '1';

    const stepElements = new Map(
        Array.from(form.querySelectorAll('[data-wizard-step]')).map((step) => [step.dataset.wizardStep, step]),
    );
    const progressTitle = form.querySelector('[data-wizard-progress-title]');
    const progressCount = form.querySelector('[data-wizard-progress-count]');
    const progressBar = form.querySelector('[data-wizard-progress-bar]');
    const nextButton = form.querySelector('[data-wizard-next]');
    const backButton = form.querySelector('[data-wizard-back]');
    const skipButton = form.querySelector('[data-wizard-skip]');
    const actions = form.querySelector('[data-wizard-actions]');
    const typeRadios = Array.from(form.querySelectorAll('[data-wizard-type]'));
    const formatRadios = Array.from(form.querySelectorAll('[data-game-format]'));
    const recruitmentRadios = Array.from(form.querySelectorAll('[data-recruitment-mode]'));
    const periodsRadios = Array.from(form.querySelectorAll('input[name="periods_count"]'));
    const gamePayload = Array.from(form.querySelectorAll('[data-game-payload]'));
    const customFormatFields = form.querySelector('[data-custom-format-fields]');
    const periodsCard = form.querySelector('[data-periods-card]');
    const customSideA = form.querySelector('[data-custom-side-a]');
    const customSideB = form.querySelector('[data-custom-side-b]');
    const customScoring = form.querySelector('[data-custom-scoring]');
    const customTiming = form.querySelector('[data-custom-timing]');
    const sideAInput = form.querySelector('[data-game-side-a]');
    const sideBInput = form.querySelector('[data-game-side-b]');
    const scoringInput = form.querySelector('[data-game-scoring]');
    const timingInput = form.querySelector('[data-game-timing]');
    const startsAt = form.querySelector('[data-wizard-start]');
    const duration = form.querySelector('[data-wizard-duration]');
    const titleInput = form.querySelector('[data-wizard-title]');
    const visibilityInput = form.querySelector('[data-wizard-visibility]');
    const venueSelector = form.querySelector('[data-venue-selector]');
    const venueText = venueSelector?.querySelector('[data-venue-selector-input]');
    const venueValue = venueSelector?.querySelector('[data-venue-selector-value]');
    const scopeWrap = venueSelector?.querySelector('[data-venue-booking-scope]');
    const scopeInput = venueSelector?.querySelector('[data-venue-booking-scope-input]');
    const teamPickerWrap = form.querySelector('[data-team-picker-wrap]');
    const individualRecruitment = form.querySelector('[data-individual-recruitment]');
    const trainingParticipants = form.querySelector('[data-training-participants]');
    const participantsCopy = form.querySelector('[data-participants-copy]');
    const publishTelegram = form.querySelector('[data-publish-telegram]');
    const telegramChats = form.querySelector('[data-telegram-chats]');
    const telegramError = form.querySelector('[data-telegram-error]');
    const submitButton = form.querySelector('[data-wizard-submit]');
    const scheduleSummary = form.querySelector('[data-schedule-summary]');

    const teamAId = form.querySelector('[data-team-a-id]');
    const teamBId = form.querySelector('[data-team-b-id]');
    const teamSlots = Array.from(form.querySelectorAll('[data-team-slot]'));
    const teamSearch = form.querySelector('[data-team-search]');
    const teamSearchStatus = form.querySelector('[data-team-search-status]');
    const teamGrid = form.querySelector('[data-team-grid]');
    const teamEmpty = form.querySelector('[data-team-empty]');

    const initialTeamIds = {
        A: Number(teamAId?.value || 0) || null,
        B: Number(teamBId?.value || 0) || null,
    };
    const selectedTeams = { A: null, B: null };

    function resolveInitialStep() {
        const fieldErrorStep = Array.from(stepElements.entries()).find(([, step]) => (
            step.querySelector('.invalid-feedback.d-block')
        ));
        if (fieldErrorStep) return fieldErrorStep[0];

        const page = form.closest('.event-wizard-page');
        return page?.querySelector('.alert.alert-danger') ? 'review' : 'type';
    }

    let currentStep = resolveInitialStep();
    let activeSteps = [];
    let generatedTitle = titleInput?.dataset.generatedTitle === '0'
        ? false
        : Boolean(titleInput && titleInput.value === form.dataset.defaultTitle);
    let allowSubmit = false;
    let activeTeamSlot = 'A';
    let teamSearchTimer = null;
    let teamSearchController = null;
    let teamStateHydrated = initialTeamIds.A === null && initialTeamIds.B === null;

    const typeLabels = {
        game: 'Игра',
        game_training: 'Игровая тренировка',
        training: 'Тренировка',
    };
    const formatLabels = {
        basketball_5x5: 'Баскетбол 5×5',
        streetball_3x3: 'Стритбол 3×3',
        streetball_1x1: 'Стритбол 1×1',
        custom: 'Свой формат',
    };

    const selectedValue = (radios) => radios.find((radio) => radio.checked)?.value || '';
    const currentType = () => selectedValue(typeRadios);
    const currentFormat = () => selectedValue(formatRadios);
    const currentRecruitment = () => selectedValue(recruitmentRadios);
    const computeSteps = () => currentType() === 'game'
        ? ['type', 'game', 'schedule', 'venue', 'participants', 'details', 'publication', 'review']
        : ['type', 'schedule', 'venue', 'participants', 'details', 'publication', 'review'];

    function rebuildSteps(preferredStep = currentStep) {
        activeSteps = computeSteps();
        if (!activeSteps.includes(preferredStep)) preferredStep = activeSteps[0];
        currentStep = preferredStep;
        refreshStepNumbers();
        showCurrentStep();
    }

    function refreshStepNumbers() {
        activeSteps.forEach((key, index) => {
            const number = stepElements.get(key)?.querySelector('.event-wizard-step__number');
            if (number && key !== 'review') number.textContent = String(index + 1).padStart(2, '0');
        });
    }

    function showCurrentStep() {
        stepElements.forEach((element, key) => {
            element.hidden = key !== currentStep || !activeSteps.includes(key);
        });

        const index = Math.max(0, activeSteps.indexOf(currentStep));
        const step = stepElements.get(currentStep);
        if (progressTitle) progressTitle.textContent = step?.dataset.stepTitle || 'Создание мероприятия';
        if (progressCount) progressCount.textContent = `${index + 1} из ${activeSteps.length}`;
        if (progressBar) progressBar.style.width = `${((index + 1) / activeSteps.length) * 100}%`;
        if (backButton) backButton.hidden = index === 0;
        if (nextButton) nextButton.hidden = currentStep === 'review';
        if (actions) actions.hidden = currentStep === 'review';
        if (skipButton) skipButton.hidden = !['participants', 'details', 'publication'].includes(currentStep);

        updateSummary();
        window.scrollTo({
            top: Math.max(0, form.getBoundingClientRect().top + window.scrollY - 110),
            behavior: 'smooth',
        });
    }

    function go(direction) {
        const index = activeSteps.indexOf(currentStep);
        const nextIndex = index + direction;
        if (nextIndex < 0 || nextIndex >= activeSteps.length) return;
        currentStep = activeSteps[nextIndex];
        showCurrentStep();
    }

    function validateCurrentStep() {
        if (currentStep === 'publication' && !validateTelegram()) return false;
        const step = stepElements.get(currentStep);
        if (!step) return true;

        const controls = Array.from(step.querySelectorAll('input, select, textarea'))
            .filter((control) => !control.disabled && control.type !== 'hidden');
        for (const control of controls) {
            if (!control.checkValidity()) {
                control.reportValidity();
                return false;
            }
        }

        if (currentStep === 'venue' && venueValue && !venueValue.value) {
            venueText?.setCustomValidity('Выберите площадку из списка.');
            venueText?.reportValidity();
            return false;
        }

        return true;
    }

    nextButton?.addEventListener('click', () => {
        if (validateCurrentStep()) go(1);
    });
    backButton?.addEventListener('click', () => go(-1));
    skipButton?.addEventListener('click', () => go(1));

    form.querySelectorAll('[data-review-edit]').forEach((button) => {
        button.addEventListener('click', () => {
            const target = button.dataset.reviewEdit;
            if (activeSteps.includes(target)) {
                currentStep = target;
                showCurrentStep();
            }
        });
    });

    typeRadios.forEach((radio) => radio.addEventListener('change', () => {
        if (currentType() !== 'game') clearTeams();
        syncGameEnabled();
        syncParticipantsMode();
        syncVenueScope();
        updateGeneratedTitle();
        rebuildSteps('type');
    }));

    formatRadios.forEach((radio) => radio.addEventListener('change', () => {
        syncFormat();
        updateGeneratedTitle();
        syncVenueScope();
        updateSummary();
    }));

    recruitmentRadios.forEach((radio) => radio.addEventListener('change', () => {
        if (currentRecruitment() === 'individual_draft') {
            clearTeams();
        } else if (currentType() === 'game') {
            loadTeams(teamSearch?.value.trim() || '');
        }
        syncFormat();
        syncParticipantsMode();
        updateSummary();
    }));

    [customSideA, customSideB, customScoring, customTiming].filter(Boolean).forEach((field) => {
        field.addEventListener('change', () => {
            syncFormat();
            syncVenueScope();
            updateSummary();
        });
    });
    periodsRadios.forEach((radio) => radio.addEventListener('change', updateSummary));

    function syncGameEnabled() {
        const isGame = currentType() === 'game';
        gamePayload.forEach((field) => { field.disabled = !isGame; });
        formatRadios.forEach((field) => { field.disabled = !isGame; });
        recruitmentRadios.forEach((field) => { field.disabled = !isGame; });
        periodsRadios.forEach((field) => { field.disabled = !isGame || timingInput?.value !== 'periods'; });
        if (formatRadios[0]) formatRadios[0].required = isGame;
        if (recruitmentRadios[0]) recruitmentRadios[0].required = isGame;
        syncFormat();
    }

    function syncFormat() {
        if (currentType() !== 'game') return;
        const format = currentFormat() || 'streetball_3x3';
        let sideA = 3;
        let sideB = 3;
        let scoring = 'streetball';
        let timing = 'whole_game';

        if (format === 'basketball_5x5') {
            sideA = 5;
            sideB = 5;
            scoring = 'basketball';
            timing = 'periods';
        } else if (format === 'streetball_1x1') {
            sideA = 1;
            sideB = 1;
        } else if (format === 'custom') {
            sideA = Number(customSideA?.value || 3);
            sideB = Number(customSideB?.value || 3);
            scoring = customScoring?.value || 'streetball';
            timing = customTiming?.value || 'whole_game';
        }

        if (currentRecruitment() === 'individual_draft') {
            sideB = sideA;
            if (customSideB) customSideB.value = String(sideA);
        }

        if (sideAInput) sideAInput.value = String(sideA);
        if (sideBInput) sideBInput.value = String(sideB);
        if (scoringInput) scoringInput.value = scoring;
        if (timingInput) timingInput.value = timing;
        if (customFormatFields) customFormatFields.hidden = format !== 'custom';
        if (periodsCard) periodsCard.hidden = timing !== 'periods';
        periodsRadios.forEach((field, index) => {
            field.disabled = timing !== 'periods';
            field.required = timing === 'periods' && index === 0;
        });
    }

    function syncParticipantsMode() {
        const isGame = currentType() === 'game';
        const individual = isGame && currentRecruitment() === 'individual_draft';
        if (teamPickerWrap) teamPickerWrap.hidden = !isGame || individual;
        if (individualRecruitment) individualRecruitment.hidden = !individual;
        if (trainingParticipants) trainingParticipants.hidden = isGame;
        if (participantsCopy) {
            participantsCopy.textContent = isGame
                ? (individual
                    ? 'Игроков можно пригласить позже — этот шаг не блокирует создание.'
                    : 'Команды выбирать необязательно. Можно создать игру и набрать стороны позже.')
                : 'Участников можно добавить после создания. Сейчас достаточно определить лимит, если он нужен.';
        }
    }

    function syncVenueScope() {
        if (!scopeWrap || !scopeInput || !venueSelector) return;
        const supportsHalves = Number(venueSelector.dataset.selectedHoopsCount || 0) >= 2;
        scopeWrap.hidden = !supportsHalves;
        if (!supportsHalves) {
            const changed = scopeInput.value !== 'whole';
            scopeInput.value = 'whole';
            if (changed) scopeInput.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    venueValue?.addEventListener('change', () => {
        window.setTimeout(() => {
            syncVenueScope();
            updateSummary();
        }, 0);
    });
    scopeInput?.addEventListener('change', updateSummary);
    venueText?.addEventListener('input', updateSummary);

    [startsAt, duration].filter(Boolean).forEach((field) => {
        field.addEventListener('change', () => {
            updateGeneratedTitle();
            updateSummary();
        });
    });

    titleInput?.addEventListener('input', () => {
        generatedTitle = false;
        updateSummary();
    });
    visibilityInput?.addEventListener('change', updateSummary);

    function updateGeneratedTitle() {
        if (!titleInput || !generatedTitle) return;
        const type = currentType();
        const typeLabel = typeLabels[type] || 'Мероприятие';
        let formatPart = '';
        if (type === 'game') {
            formatPart = {
                basketball_5x5: ' 5×5',
                streetball_3x3: ' 3×3',
                streetball_1x1: ' 1×1',
                custom: '',
            }[currentFormat()] || '';
        }
        const date = parseLocalDate(startsAt?.value);
        const datePart = date ? ` — ${two(date.getDate())}.${two(date.getMonth() + 1)}` : '';
        titleInput.value = `${typeLabel}${formatPart}${datePart}`;
        updateSummary();
    }

    function scheduleLabel() {
        const start = parseLocalDate(startsAt?.value);
        if (!start) return 'Не выбрано';
        const minutes = Number(duration?.value || 0);
        const end = new Date(start.getTime() + minutes * 60000);
        return `${two(start.getDate())}.${two(start.getMonth() + 1)} · ${two(start.getHours())}:${two(start.getMinutes())}–${two(end.getHours())}:${two(end.getMinutes())}`;
    }

    function parseLocalDate(value) {
        if (!value) return null;
        const date = new Date(value);
        return Number.isNaN(date.getTime()) ? null : date;
    }

    function two(value) {
        return String(value).padStart(2, '0');
    }

    function updateSummary() {
        const type = currentType();
        const format = currentFormat();
        const recruitment = currentRecruitment();
        const schedule = scheduleLabel();
        const venue = venueText?.value?.trim() || 'Не выбрано';
        const participants = participantLabel();
        const title = titleInput?.value?.trim() || 'Без названия';
        const gameText = type === 'game'
            ? `${formatLabels[format] || 'Формат не выбран'} · ${recruitment === 'individual_draft' ? 'отдельные игроки' : 'готовые команды'}`
            : '';

        if (scheduleSummary) scheduleSummary.textContent = schedule;
        setText('[data-summary-title]', title);
        setText('[data-summary-type]', typeLabels[type] || '—');
        setText('[data-summary-game]', gameText || '—');
        setText('[data-summary-schedule]', schedule);
        setText('[data-summary-venue]', venue);
        setText('[data-summary-participants]', participants);
        setText('[data-review-type]', typeLabels[type] || '—');
        setText('[data-review-game]', gameText || '—');
        setText('[data-review-schedule]', schedule);
        setText('[data-review-venue]', venue);
        setText('[data-review-participants]', participants);
        setText('[data-review-title]', title);

        form.querySelectorAll('[data-summary-game-row], [data-review-game-row]').forEach((row) => {
            row.hidden = type !== 'game';
        });
        if (submitButton) {
            submitButton.textContent = type === 'game'
                ? 'Создать игру'
                : (type === 'game_training' ? 'Создать игровую тренировку' : 'Создать тренировку');
        }
        updateReviewWarning();
    }

    function setText(selector, value) {
        form.querySelectorAll(selector).forEach((element) => {
            element.textContent = value;
        });
    }

    teamSlots.forEach((slot) => slot.addEventListener('click', () => {
        activateTeamSlot(slot.dataset.teamSlot);
        teamSearch?.focus();
    }));

    teamSearch?.addEventListener('input', () => {
        window.clearTimeout(teamSearchTimer);
        teamSearchTimer = window.setTimeout(() => loadTeams(teamSearch.value.trim()), 250);
    });

    async function loadTeams(query = '', ids = []) {
        if (!form.dataset.teamSearchUrl || !teamGrid) return [];
        teamSearchController?.abort();
        const controller = new AbortController();
        teamSearchController = controller;
        if (teamSearchStatus) teamSearchStatus.textContent = 'Ищем…';

        try {
            const params = new URLSearchParams({ q: query, limit: '32' });
            ids.filter(Boolean).forEach((id) => params.append('ids[]', String(id)));
            const response = await fetch(`${form.dataset.teamSearchUrl}?${params.toString()}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: controller.signal,
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(payload.message || 'Не удалось загрузить команды.');
            const teams = Array.isArray(payload.teams) ? payload.teams : [];
            renderTeams(teams);
            if (teamSearchStatus) teamSearchStatus.textContent = '';
            return teams;
        } catch (error) {
            if (error.name !== 'AbortError' && teamSearchStatus) teamSearchStatus.textContent = error.message;
            return [];
        } finally {
            if (teamSearchController === controller) teamSearchController = null;
        }
    }

    function renderTeams(teams) {
        if (!teamGrid) return;
        teamGrid.replaceChildren();
        if (teamEmpty) teamEmpty.hidden = teams.length !== 0;

        teams.forEach((team) => {
            const button = document.createElement('button');
            const imageWrap = document.createElement('span');
            const image = document.createElement('img');
            const name = document.createElement('strong');
            const hint = document.createElement('small');

            button.type = 'button';
            button.className = 'event-wizard-team-card';
            button.classList.toggle('is-selected', selectedTeams.A?.id === team.id || selectedTeams.B?.id === team.id);
            imageWrap.className = 'event-wizard-team-card__logo';
            image.src = team.logo_url;
            image.alt = '';
            image.loading = 'lazy';
            name.textContent = team.name;
            hint.textContent = team.manageable ? 'Ваша команда' : 'Можно пригласить';
            imageWrap.append(image);
            button.append(imageWrap, name, hint);
            button.addEventListener('click', () => selectTeam(team));
            teamGrid.append(button);
        });
    }

    function selectTeam(team) {
        teamStateHydrated = true;
        const otherSide = activeTeamSlot === 'A' ? 'B' : 'A';
        if (selectedTeams[otherSide]?.id === team.id) {
            if (teamSearchStatus) teamSearchStatus.textContent = 'Одна команда не может играть за обе стороны.';
            return;
        }

        selectedTeams[activeTeamSlot] = selectedTeams[activeTeamSlot]?.id === team.id ? null : team;
        syncTeamInputs();
        renderTeamSlots();
        loadTeams(teamSearch?.value.trim() || '');
        if (selectedTeams.A && !selectedTeams.B) activateTeamSlot('B');
        updateSummary();
    }

    function activateTeamSlot(side) {
        activeTeamSlot = side === 'B' ? 'B' : 'A';
        teamSlots.forEach((slot) => {
            slot.classList.toggle('is-active', slot.dataset.teamSlot === activeTeamSlot);
        });
    }

    function syncTeamInputs() {
        if (teamAId) {
            teamAId.value = selectedTeams.A?.id
                || (!teamStateHydrated && initialTeamIds.A ? String(initialTeamIds.A) : '');
        }
        if (teamBId) {
            teamBId.value = selectedTeams.B?.id
                || (!teamStateHydrated && initialTeamIds.B ? String(initialTeamIds.B) : '');
        }
    }

    function renderTeamSlots() {
        teamSlots.forEach((slot) => {
            const side = slot.dataset.teamSlot;
            const team = selectedTeams[side];
            const name = slot.querySelector('[data-team-slot-name]');
            const hint = slot.querySelector('[data-team-slot-hint]');
            const logo = slot.querySelector('.event-wizard-team-slot__logo');

            if (name) name.textContent = team?.name || 'Выбрать команду';
            if (hint) hint.textContent = team?.selection_hint || 'Можно сделать позже';
            if (logo) {
                logo.replaceChildren();
                if (team) {
                    const image = document.createElement('img');
                    image.src = team.logo_url;
                    image.alt = '';
                    logo.append(image);
                } else {
                    const icon = document.createElement('i');
                    icon.className = 'ti ti-plus';
                    logo.append(icon);
                }
            }
            slot.classList.toggle('has-team', Boolean(team));
        });
    }

    function clearTeams() {
        teamStateHydrated = true;
        selectedTeams.A = null;
        selectedTeams.B = null;
        syncTeamInputs();
        renderTeamSlots();
        updateSummary();
    }

    async function hydrateInitialTeams() {
        const ids = [initialTeamIds.A, initialTeamIds.B].filter(Boolean);
        if (!ids.length || currentType() !== 'game' || currentRecruitment() !== 'preformed_teams') {
            teamStateHydrated = true;
            syncTeamInputs();
            if (currentType() === 'game' && currentRecruitment() === 'preformed_teams') await loadTeams();
            return;
        }

        const teams = await loadTeams('', ids);
        selectedTeams.A = teams.find((team) => Number(team.id) === initialTeamIds.A) || null;
        selectedTeams.B = teams.find((team) => Number(team.id) === initialTeamIds.B) || null;
        teamStateHydrated = true;
        syncTeamInputs();
        renderTeamSlots();
        updateSummary();
    }

    function participantLabel() {
        if (currentType() !== 'game') return 'Добавим позже';
        if (currentRecruitment() === 'individual_draft') return 'Отдельные игроки · набор позже';
        const names = [selectedTeams.A?.name, selectedTeams.B?.name].filter(Boolean);
        if (names.length) return names.join(' vs ');
        if (!teamStateHydrated && (initialTeamIds.A || initialTeamIds.B)) return 'Восстанавливаем команды…';
        return 'Команды определим позже';
    }

    function updateReviewWarning() {
        const warning = form.querySelector('[data-review-warning]');
        const text = warning?.querySelector('span');
        if (!warning || !text) return;

        const messages = [];
        if (currentType() === 'game' && currentRecruitment() === 'preformed_teams') {
            if (!selectedTeams.A || !selectedTeams.B) {
                messages.push('Одну или обе команды можно выбрать позже. До старта игры должны быть утверждены обе стороны.');
            }
            const external = [selectedTeams.A, selectedTeams.B].filter((team) => team && !team.manageable);
            if (external.length) {
                messages.push('Выбранным чужим командам будут отправлены приглашения; участие подтверждается только после ответа представителя.');
            }
        }
        warning.hidden = messages.length === 0;
        text.textContent = messages.join(' ');
    }

    publishTelegram?.addEventListener('change', () => {
        if (telegramChats) telegramChats.hidden = !publishTelegram.checked;
        if (telegramError) telegramError.hidden = true;
        updateSummary();
    });

    function validateTelegram() {
        if (!publishTelegram?.checked) return true;
        const checked = form.querySelectorAll('input[name="telegram_chat_ids[]"]:checked').length;
        if (telegramError) telegramError.hidden = checked > 0;
        return checked > 0;
    }

    form.addEventListener('submit', (event) => {
        if (allowSubmit) {
            if (submitButton) {
                submitButton.disabled = true;
                submitButton.textContent = 'Создаём…';
            }
            return;
        }
        event.preventDefault();

        // Native Enter submission from an input must never bypass the review step.
        if (currentStep !== 'review') return;

        syncGameEnabled();
        syncFormat();
        syncTeamInputs();

        if (!validateTelegram()) {
            currentStep = 'publication';
            showCurrentStep();
            return;
        }

        const invalid = Array.from(form.elements).find((control) => (
            control instanceof HTMLInputElement
            || control instanceof HTMLSelectElement
            || control instanceof HTMLTextAreaElement
        ) && !control.disabled && !control.checkValidity());

        if (invalid) {
            const step = invalid.closest('[data-wizard-step]');
            if (step?.dataset.wizardStep && activeSteps.includes(step.dataset.wizardStep)) {
                currentStep = step.dataset.wizardStep;
                showCurrentStep();
            }
            window.setTimeout(() => invalid.reportValidity(), 0);
            return;
        }

        if (venueValue && !venueValue.value) {
            currentStep = 'venue';
            showCurrentStep();
            venueText?.setCustomValidity('Выберите площадку из списка.');
            window.setTimeout(() => venueText?.reportValidity(), 0);
            return;
        }

        allowSubmit = true;
        form.requestSubmit(submitButton || undefined);
    });

    syncGameEnabled();
    syncParticipantsMode();
    syncVenueScope();
    if (publishTelegram && telegramChats) telegramChats.hidden = !publishTelegram.checked;
    rebuildSteps(currentStep);
    updateGeneratedTitle();
    renderTeamSlots();
    hydrateInitialTeams();
}
