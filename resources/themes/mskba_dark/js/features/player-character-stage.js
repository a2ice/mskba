import '../../css/pages/player-character-three.css';
import {
    destroyPlayerCharacterThree,
    mountPlayerCharacterThree,
    updatePlayerCharacterThree,
} from './player-character-authored-renderer.js';
import {
    applyAuthoredBodyShape,
    updateAuthoredAccessories,
} from './player-character-authored-customization.js';

const DEFAULT_HAIRSTYLE = {
    male: 'male_fade',
    female: 'female_ponytail',
};

function parseNullableNumber(value) {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : null;
}

function clamp(value, min, max) {
    return Math.min(max, Math.max(min, value));
}

function characterField(form, key) {
    return form.querySelector(`[data-player-character-field="${key}"]`);
}

function normalizeGender(value) {
    return value === 'female' ? 'female' : 'male';
}

function selectedTeamUniform(form) {
    const select = form.querySelector('[data-player-character-team]');
    const option = select?.selectedOptions?.[0];

    return {
        teamId: option?.value || null,
        teamName: option?.dataset.teamName || '',
        uniformPrimary: option?.dataset.uniformPrimary || null,
        uniformAccent: option?.dataset.uniformAccent || null,
    };
}

function readState(stage, form) {
    const teamUniform = selectedTeamUniform(form);

    return {
        gender: normalizeGender(stage.dataset.gender),
        heightCm: parseNullableNumber(form.querySelector('[data-player-character-input="height"]')?.value),
        weightKg: parseNullableNumber(form.querySelector('[data-player-character-input="weight"]')?.value),
        bodyType: form.querySelector('[data-player-character-input="body-type"]')?.value || 'unspecified',
        chestVolume: form.querySelector('[data-player-character-input="chest-volume"]')?.value || null,
        skinTone: characterField(form, 'skin-tone')?.value || 'warm',
        hairstyle: characterField(form, 'hairstyle')?.value || DEFAULT_HAIRSTYLE[normalizeGender(stage.dataset.gender)],
        hairColor: characterField(form, 'hair-color')?.value || 'dark_brown',
        facialHair: characterField(form, 'facial-hair')?.value || 'none',
        uniformKit: characterField(form, 'uniform-kit')?.value || 'mskba_home',
        shoes: characterField(form, 'shoes')?.value || 'white',
        attributes: [...form.querySelectorAll('[data-player-character-attribute]:checked')]
            .map((input) => input.value),
        ...teamUniform,
    };
}

function syncTeamUniformPreview(form, configurator) {
    const team = selectedTeamUniform(form);
    const preview = configurator.querySelector('[data-player-character-team-kit-preview]');
    const name = configurator.querySelector('[data-player-character-team-name]');
    const colorStatus = configurator.querySelector('[data-player-character-team-color-status]');
    preview?.style.setProperty('--kit-primary', team.uniformPrimary || '#555b60');
    preview?.style.setProperty('--kit-accent', team.uniformAccent || '#08090a');
    if (name) {
        name.textContent = team.teamName;
    }

    if (colorStatus) {
        colorStatus.textContent = !team.uniformPrimary && !team.uniformAccent
            ? 'У команды не установлены домашние цвета. Используются штатные цвета формы.'
            : !team.uniformPrimary
                ? 'У команды не установлен основной домашний цвет. Используется штатный цвет формы.'
                : !team.uniformAccent
                    ? 'У команды не установлен дополнительный домашний цвет. Используется штатный цвет полосок.'
                    : '';
        colorStatus.hidden = colorStatus.textContent === '';
    }
}

function syncChoiceButtons(configurator, field, value) {
    configurator.querySelectorAll(`[data-player-character-choice="${field}"]`).forEach((button) => {
        button.setAttribute('aria-pressed', button.dataset.value === value ? 'true' : 'false');
    });
}

function setCharacterField(form, configurator, field, value) {
    const input = characterField(form, field);
    if (!input) {
        return;
    }

    input.value = value;
    syncChoiceButtons(configurator, field, value);
}

function syncRendererSpecificControls(stage, form) {
    const enabled = stage.dataset.renderMode === '3d';
    form.querySelectorAll('[data-player-character-three-settings]').forEach((section) => {
        section.hidden = !enabled;
    });
}

function syncProfileGenderControls(stage, form, configurator) {
    const gender = normalizeGender(stage.dataset.gender);
    const hairstyleInput = characterField(form, 'hairstyle');
    const compatibleButtons = [];

    configurator.querySelectorAll('[data-player-character-choice="hairstyle"]').forEach((button) => {
        const compatible = button.dataset.characterGender === gender;
        button.hidden = !compatible;
        if (compatible) {
            compatibleButtons.push(button);
        }
    });

    const hairstyleIsCompatible = compatibleButtons.some((button) => button.dataset.value === hairstyleInput?.value);
    if (!hairstyleIsCompatible && hairstyleInput) {
        setCharacterField(
            form,
            configurator,
            'hairstyle',
            DEFAULT_HAIRSTYLE[gender] || compatibleButtons[0]?.dataset.value || '',
        );
    }

    const facialHairGroup = configurator.querySelector('[data-player-character-facial-hair-group]');
    if (facialHairGroup) {
        facialHairGroup.hidden = gender === 'female';
    }

    if (gender === 'female') {
        setCharacterField(form, configurator, 'facial-hair', 'none');
    }
}

function updateAuthoredCustomization(runtime, state) {
    if (!runtime) {
        return;
    }

    applyAuthoredBodyShape(runtime, state);
    updateAuthoredAccessories(runtime, state);
}

function updateTwoDimensionalMetrics(stage, state) {
    const heightCm = state.heightCm ?? 185;
    const heightPercent = clamp(heightCm / 250 * 100, 0, 100);
    stage.style.setProperty('--player-height-percent', heightPercent.toFixed(2));
    stage.dataset.hasHeight = state.heightCm === null ? 'false' : 'true';

    if (stage.dataset.renderMode === '3d') {
        return;
    }

    const marker = stage.querySelector('[data-player-character-height-marker]');
    const label = marker?.querySelector('[data-player-character-height-label]');

    if (!marker || state.heightCm === null) {
        if (marker) {
            marker.hidden = true;
            marker.setAttribute('aria-expanded', 'false');
        }
        return;
    }

    marker.style.left = '15%';
    marker.style.top = `${(100 - heightPercent).toFixed(2)}%`;
    marker.hidden = false;
    marker.setAttribute('aria-label', `${state.heightCm} см`);
    if (label) {
        label.textContent = `${state.heightCm} см`;
    }
}

function updateStage(stage, form, runtime = null) {
    const state = readState(stage, form);
    updateTwoDimensionalMetrics(stage, state);

    if (runtime) {
        updateAuthoredCustomization(runtime, state);
        updatePlayerCharacterThree(stage, state);
    }

    stage.dispatchEvent(new CustomEvent('player-character:change', {
        bubbles: true,
        detail: state,
    }));

    return state;
}

function bindCharacterChoices(stage, form, configurator, runtimeRef) {
    configurator.querySelectorAll('[data-player-character-choice]').forEach((button) => {
        button.addEventListener('click', () => {
            const field = button.dataset.playerCharacterChoice;
            const value = button.dataset.value;
            if (!field || !value) {
                return;
            }

            setCharacterField(form, configurator, field, value);
            updateStage(stage, form, runtimeRef.current);
        });
    });
}

function bindPhysicalInputs(stage, form, runtimeRef) {
    form.querySelectorAll('[data-player-character-input]').forEach((input) => {
        input.addEventListener('change', () => updateStage(stage, form, runtimeRef.current));
        input.addEventListener('input', () => updateStage(stage, form, runtimeRef.current));
    });
}

function bindTeamUniform(stage, form, configurator, runtimeRef) {
    const select = form.querySelector('[data-player-character-team]');
    if (!select) {
        return;
    }

    syncTeamUniformPreview(form, configurator);
    select.addEventListener('change', () => {
        syncTeamUniformPreview(form, configurator);
        updateStage(stage, form, runtimeRef.current);
    });
}

function bindHeightMarker(stage) {
    const marker = stage.querySelector('[data-player-character-height-marker]');
    if (!marker) {
        return;
    }

    marker.addEventListener('click', (event) => {
        event.stopPropagation();
        marker.setAttribute(
            'aria-expanded',
            marker.getAttribute('aria-expanded') === 'true' ? 'false' : 'true',
        );
    });

    marker.addEventListener('blur', () => marker.setAttribute('aria-expanded', 'false'));
    marker.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            marker.setAttribute('aria-expanded', 'false');
            marker.blur();
        }
    });
}

function waitUntilNearViewport(stage) {
    if (!('IntersectionObserver' in window)) {
        return Promise.resolve();
    }

    const rect = stage.getBoundingClientRect();
    const preloadDistance = 320;
    if (rect.top < window.innerHeight + preloadDistance && rect.bottom > -preloadDistance) {
        return Promise.resolve();
    }

    return new Promise((resolve) => {
        const observer = new IntersectionObserver((entries) => {
            if (!entries.some((entry) => entry.isIntersecting)) {
                return;
            }

            observer.disconnect();
            resolve();
        }, {
            rootMargin: `${preloadDistance}px 0px`,
            threshold: 0.01,
        });
        observer.observe(stage);
    });
}

function csrfToken(form) {
    return form.querySelector('input[name="_token"]')?.value
        || document.querySelector('meta[name="csrf-token"]')?.content
        || '';
}

function setStageBusy(stage, busy) {
    const loading = stage.querySelector('[data-player-character-loading]');
    stage.dataset.renderBusy = busy ? 'true' : 'false';
    if (loading) {
        loading.hidden = !busy;
    }
}

function setStageError(stage, message = '') {
    const errorNode = stage.closest('form')
        ?.querySelector('[data-player-character-error]');
    if (!errorNode) {
        return;
    }

    errorNode.textContent = message;
    errorNode.hidden = message === '';
}

function syncRenderModeButtons(stage) {
    const visual = stage.closest('.account-player-character-visual');
    const form = stage.closest('form');
    const wrapper = visual?.querySelector('[data-player-character-render-switch]');
    const busy = stage.dataset.renderBusy === 'true';

    wrapper?.querySelectorAll('[data-player-character-render-mode]').forEach((button) => {
        button.setAttribute('aria-pressed', button.dataset.playerCharacterRenderMode === stage.dataset.renderMode ? 'true' : 'false');
        button.disabled = busy;
    });

    const generate = form?.querySelector('[data-player-character-generate]');
    if (generate) {
        generate.disabled = busy;
        generate.setAttribute('aria-busy', busy ? 'true' : 'false');
    }
}

async function requestJsonMutation(stage, form, payload) {
    const response = await fetch(stage.dataset.characterMutationUrl, {
        method: 'PATCH',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(form),
        },
        body: JSON.stringify(payload),
    });
    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        const error = new Error(data.message || 'Не удалось выполнить действие.');
        error.status = response.status;
        error.code = data.code || null;
        error.payload = data;
        throw error;
    }

    return data;
}

async function rollbackRenderMode(stage, form) {
    try {
        await requestJsonMutation(stage, form, {
            mutation: 'render_mode',
            render_mode: '2d',
        });
    } catch (error) {
        console.warn('Could not persist Player Character renderer rollback.', error);
    }
}

async function activateThree(stage, form, runtimeRef) {
    if (runtimeRef.current) {
        return true;
    }

    await waitUntilNearViewport(stage);
    const state = readState(stage, form);
    runtimeRef.current = await mountPlayerCharacterThree(stage, state);

    if (!runtimeRef.current) {
        return false;
    }

    updateStage(stage, form, runtimeRef.current);
    return true;
}

function activateTwo(stage, form, runtimeRef) {
    stage.dataset.renderMode = '2d';
    syncRendererSpecificControls(stage, form);
    updateStage(stage, form, null);

    if (runtimeRef.current) {
        destroyPlayerCharacterThree(stage);
        runtimeRef.current = null;
    }
}

function bindRenderModeSwitch(stage, form, runtimeRef) {
    const wrapper = stage.closest('.account-player-character-visual')
        ?.querySelector('[data-player-character-render-switch]');
    if (!wrapper) {
        return;
    }

    syncRenderModeButtons(stage);

    wrapper.querySelectorAll('[data-player-character-render-mode]').forEach((button) => {
        button.addEventListener('click', async () => {
            const requestedMode = button.dataset.playerCharacterRenderMode;
            const previousMode = stage.dataset.renderMode || '2d';

            if (!requestedMode || requestedMode === previousMode || stage.dataset.renderBusy === 'true') {
                return;
            }

            setStageBusy(stage, true);
            setStageError(stage, '');
            syncRenderModeButtons(stage);

            try {
                const result = await requestJsonMutation(stage, form, {
                    mutation: 'render_mode',
                    render_mode: requestedMode,
                });

                if (result.render_mode === '3d') {
                    const ready = await activateThree(stage, form, runtimeRef);
                    if (!ready) {
                        await rollbackRenderMode(stage, form);
                        activateTwo(stage, form, runtimeRef);
                        setStageError(stage, 'Не удалось загрузить 3D-модель. 2D-модель осталась активной.');
                        return;
                    }

                    stage.dataset.renderMode = '3d';
                    syncRendererSpecificControls(stage, form);
                    updateStage(stage, form, runtimeRef.current);
                } else {
                    activateTwo(stage, form, runtimeRef);
                }
            } catch (error) {
                stage.dataset.renderMode = previousMode;
                syncRendererSpecificControls(stage, form);
                if (previousMode === '2d') {
                    updateStage(stage, form, null);
                }
                setStageError(stage, error.message || 'Не удалось переключить режим отображения.');
            } finally {
                setStageBusy(stage, false);
                syncRenderModeButtons(stage);
            }
        });
    });
}

function syncFaceValidationNote(form) {
    const note = form.querySelector('[data-player-character-face-validation-note]');
    if (!note) {
        return;
    }

    const front = form.querySelector('[data-player-character-face-card="front"]');
    const left = form.querySelector('[data-player-character-face-card="left"]');
    const right = form.querySelector('[data-player-character-face-card="right"]');
    const hasPending = Boolean(
        form.querySelector('[data-player-character-face-card].is-preview-unconfirmed'),
    );
    const hasConfirmedSet = Boolean(
        front?.classList.contains('is-stored')
        && (left?.classList.contains('is-stored') || right?.classList.contains('is-stored')),
    );

    note.hidden = !hasPending && hasConfirmedSet;
}

function previewFaceReference(stage, form, input) {
    const slot = input.dataset.playerCharacterFaceInput;
    const file = input.files?.[0];
    const container = form.querySelector('[data-player-character-face-references]');
    const card = container?.querySelector(`[data-player-character-face-card="${slot}"]`);
    const image = card?.querySelector('[data-player-character-face-image]');

    if (!slot || !file || !card || !image) {
        return;
    }

    const previousObjectUrl = card.dataset.faceObjectUrl;
    if (previousObjectUrl) {
        URL.revokeObjectURL(previousObjectUrl);
    }

    const objectUrl = URL.createObjectURL(file);
    card.dataset.faceObjectUrl = objectUrl;
    image.src = objectUrl;
    image.hidden = false;
    card.classList.add('has-preview', 'is-preview-unconfirmed');
    card.classList.remove('is-uploading');
    setStageError(stage, '');
    syncFaceValidationNote(form);
}

function pendingFaceInputs(form) {
    return [...form.querySelectorAll('[data-player-character-face-input]')]
        .filter((input) => input.files?.[0]);
}

function setPendingFacesBusy(form, busy) {
    pendingFaceInputs(form).forEach((input) => {
        const slot = input.dataset.playerCharacterFaceInput;
        const card = form.querySelector(`[data-player-character-face-card="${slot}"]`);

        input.disabled = busy;
        card?.classList.toggle('is-uploading', busy);
    });
}

function appendGenerationValue(data, key, value) {
    data.append(key, value === null || value === undefined ? '' : String(value));
}

function generationRequestData(stage, form) {
    const payload = generationOptionsPayload(stage, form);
    const data = new FormData();

    data.append('_method', 'PATCH');
    data.append('_token', csrfToken(form));
    data.append('mutation', 'generate_2d');

    appendGenerationValue(data, 'height_cm', payload.height_cm);
    appendGenerationValue(data, 'weight_kg', payload.weight_kg);
    appendGenerationValue(data, 'body_type', payload.body_type);
    appendGenerationValue(data, 'generation_team_id', payload.generation_team_id);

    Object.entries(payload.character).forEach(([key, value]) => {
        if (key === 'attributes') {
            value.forEach((attribute) => data.append('character[attributes][]', attribute));
            return;
        }

        appendGenerationValue(data, `character[${key}]`, value);
    });

    pendingFaceInputs(form).forEach((input) => {
        const slot = input.dataset.playerCharacterFaceInput;
        const file = input.files?.[0];

        if (slot && file) {
            data.append(`generation_face_references[${slot}]`, file);
        }
    });

    return data;
}

async function requestGenerationMutation(stage, form) {
    const response = await fetch(stage.dataset.characterMutationUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
        body: generationRequestData(stage, form),
    });
    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        const validationMessage = data.errors
            ? Object.values(data.errors).flat()[0]
            : null;
        const error = new Error(validationMessage || data.message || 'Не удалось выполнить генерацию.');
        error.status = response.status;
        error.code = data.code || null;
        error.payload = data;
        throw error;
    }

    return data;
}

function wait(milliseconds) {
    return new Promise((resolve) => window.setTimeout(resolve, milliseconds));
}

async function waitForGeneration(statusUrl) {
    const maximumAttempts = 300;

    for (let attempt = 0; attempt < maximumAttempts; attempt += 1) {
        if (attempt > 0) {
            await wait(4000);
        }

        const response = await fetch(statusUrl, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        const result = await response.json().catch(() => ({}));

        if (!response.ok) {
            const error = new Error(result.message || 'Не удалось проверить статус генерации.');
            error.status = response.status;
            error.code = result.code || null;
            error.payload = result;
            throw error;
        }

        if (result.status === 'completed') {
            return result;
        }

        if (result.status === 'failed') {
            const error = new Error(result.message || 'Не удалось сгенерировать 2D-модель.');
            error.code = result.code || 'generation_failed';
            error.payload = result;
            throw error;
        }
    }

    const error = new Error('Генерация занимает слишком много времени. Проверьте результат позже.');
    error.code = 'generation_timeout';
    throw error;
}

function applyValidatedFacePreviews(form, previews = {}) {
    Object.entries(previews).forEach(([slot, url]) => {
        const card = form.querySelector(`[data-player-character-face-card="${slot}"]`);
        const image = card?.querySelector('[data-player-character-face-image]');
        const input = card?.querySelector('[data-player-character-face-input]');

        if (!card || !image || !input || !url) {
            return;
        }

        const objectUrl = card.dataset.faceObjectUrl;
        if (objectUrl) {
            URL.revokeObjectURL(objectUrl);
            delete card.dataset.faceObjectUrl;
        }

        image.src = url;
        image.hidden = false;
        input.disabled = false;
        input.value = '';
        card.classList.add('is-stored', 'has-preview');
        card.classList.remove('is-preview-unconfirmed', 'is-uploading');
    });

    syncFaceValidationNote(form);
}

function formatRubles(minor) {
    const value = Number(minor);
    if (!Number.isFinite(value)) {
        return null;
    }

    return new Intl.NumberFormat('ru-RU', {
        style: 'currency',
        currency: 'RUB',
        maximumFractionDigits: value % 100 === 0 ? 0 : 2,
    }).format(value / 100);
}

function generationOptionsPayload(stage, form) {
    const state = readState(stage, form);

    return {
        height_cm: state.heightCm,
        weight_kg: state.weightKg,
        body_type: state.bodyType === 'unspecified' ? null : state.bodyType,
        generation_team_id: state.teamId ? Number(state.teamId) : null,
        character: {
            skin_tone: state.skinTone,
            hairstyle: state.hairstyle,
            hair_color: state.hairColor,
            facial_hair: state.facialHair,
            uniform_kit: state.uniformKit,
            shoes: state.shoes,
            attributes: state.attributes,
            chest_volume: state.chestVolume,
        },
    };
}

function generationErrorMessage(error) {
    if (error?.code !== 'insufficient_balance') {
        return error?.message || 'Не удалось сгенерировать 2D-модель.';
    }

    const price = formatRubles(error.payload?.price_minor);
    const available = formatRubles(error.payload?.available_minor);

    if (price && available) {
        return `Недостаточно средств. Стоимость генерации — ${price}, доступно — ${available}.`;
    }

    return error.message;
}

function bindGenerateTwoDimensional(stage, form) {
    const button = form.querySelector('[data-player-character-generate]');

    if (!button) {
        return;
    }

    button.addEventListener('click', async () => {
        if (stage.dataset.renderBusy === 'true') {
            return;
        }

        setStageBusy(stage, true);
        setStageError(stage, '');
        syncRenderModeButtons(stage);

        try {
            setPendingFacesBusy(form, true);
            const result = await requestGenerationMutation(stage, form);
            applyValidatedFacePreviews(form, result.face_previews);

            const completed = result.status === 'pending' && result.status_url
                ? await waitForGeneration(result.status_url)
                : result;

            const image = stage.querySelector('[data-player-character-two-image]');
            const imageUrl = completed.image_url || completed.image_data_url;
            if (image && imageUrl) {
                image.src = imageUrl;
                image.classList.remove('is-placeholder');
                image.removeAttribute('data-placeholder-gender');
            }
        } catch (error) {
            applyValidatedFacePreviews(form, error?.payload?.face_previews || {});
            setStageError(stage, generationErrorMessage(error));
        } finally {
            setPendingFacesBusy(form, false);
            setStageBusy(stage, false);
            syncRenderModeButtons(stage);
        }
    });
}

function bindCharacterAttributes(stage, form, runtimeRef) {
    form.querySelectorAll('[data-player-character-attribute]').forEach((input) => {
        input.addEventListener('change', () => updateStage(stage, form, runtimeRef.current));
    });
}

function bindFaceReferences(stage, form) {
    form.querySelectorAll('[data-player-character-face-input]').forEach((input) => {
        input.addEventListener('change', () => previewFaceReference(stage, form, input));
    });

    syncFaceValidationNote(form);
}

async function bindPlayerCharacterStage(stage) {
    const form = stage.closest('form');
    const configurator = form?.querySelector('[data-player-character-configurator]');
    if (!form || !configurator) {
        return;
    }

    const runtimeRef = { current: null };

    syncProfileGenderControls(stage, form, configurator);
    syncRendererSpecificControls(stage, form);
    bindCharacterChoices(stage, form, configurator, runtimeRef);
    bindPhysicalInputs(stage, form, runtimeRef);
    bindTeamUniform(stage, form, configurator, runtimeRef);
    bindHeightMarker(stage);
    bindFaceReferences(stage, form);
    bindCharacterAttributes(stage, form, runtimeRef);
    bindGenerateTwoDimensional(stage, form);
    bindRenderModeSwitch(stage, form, runtimeRef);

    const initialState = updateStage(stage, form);

    if (stage.dataset.renderMode !== '3d') {
        return;
    }

    setStageBusy(stage, true);
    syncRenderModeButtons(stage);
    const ready = await activateThree(stage, form, runtimeRef);

    if (!ready) {
        await rollbackRenderMode(stage, form);
        activateTwo(stage, form, runtimeRef);
        setStageError(stage, 'Не удалось загрузить 3D-модель. 2D-модель осталась активной.');
    } else {
        updateStage(stage, form, runtimeRef.current || null);
    }

    setStageBusy(stage, false);
    syncRenderModeButtons(stage);
    stage.dispatchEvent(new CustomEvent('player-character:ready', {
        bubbles: true,
        detail: initialState,
    }));
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-player-character-stage]').forEach(bindPlayerCharacterStage);
});
