import '../../css/pages/player-character-three.css';
import { mountPlayerCharacterThree, updatePlayerCharacterThree } from './player-character-authored-renderer.js';
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
        skinTone: characterField(form, 'skin-tone')?.value || 'warm',
        hairstyle: characterField(form, 'hairstyle')?.value || DEFAULT_HAIRSTYLE[normalizeGender(stage.dataset.gender)],
        hairColor: characterField(form, 'hair-color')?.value || 'dark_brown',
        facialHair: characterField(form, 'facial-hair')?.value || 'none',
        uniformKit: characterField(form, 'uniform-kit')?.value || 'mskba_home',
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

function updateStage(stage, form, runtime = null) {
    const state = readState(stage, form);
    stage.dataset.hasHeight = state.heightCm === null ? 'false' : 'true';
    updatePlayerCharacterThree(stage, state);
    updateAuthoredCustomization(runtime, state);

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

async function bindPlayerCharacterStage(stage) {
    const form = stage.closest('form');
    const configurator = form?.querySelector('[data-player-character-configurator]');
    if (!form || !configurator) {
        return;
    }

    const runtimeRef = { current: null };

    syncProfileGenderControls(stage, form, configurator);
    bindCharacterChoices(stage, form, configurator, runtimeRef);
    bindPhysicalInputs(stage, form, runtimeRef);
    bindTeamUniform(stage, form, configurator, runtimeRef);
    bindHeightMarker(stage);

    const initialState = updateStage(stage, form);
    await waitUntilNearViewport(stage);

    runtimeRef.current = await mountPlayerCharacterThree(stage, initialState);
    if (!runtimeRef.current) {
        return;
    }

    updateStage(stage, form, runtimeRef.current);
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-player-character-stage]').forEach(bindPlayerCharacterStage);
});
