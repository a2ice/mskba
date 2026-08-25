import '../../css/pages/player-character-three.css';
import { parseNullableNumber, renderPlayerCharacter } from './player-character-svg-renderer.js';
import { mountPlayerCharacterThree, updatePlayerCharacterThree } from './player-character-three-renderer.js';

const DEFAULT_HAIRSTYLE = {
    male: 'male_fade',
    female: 'female_ponytail',
};

function characterField(form, key) {
    return form.querySelector(`[data-player-character-field="${key}"]`);
}

function normalizeGender(value) {
    return value === 'female' ? 'female' : 'male';
}

function readState(stage, form) {
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
    };
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

function updateMetricScale(stage, state) {
    const previewHeightCm = state.heightCm ?? 180;
    const normalizedHeightCm = Math.min(250, Math.max(0, previewHeightCm));
    const heightPercent = normalizedHeightCm / 250 * 100;

    stage.dataset.hasHeight = state.heightCm === null ? 'false' : 'true';
    stage.style.setProperty('--player-height-percent', heightPercent.toFixed(4));

    const label = stage.querySelector('[data-player-character-height-label]');

    if (label) {
        label.textContent = state.heightCm === null ? 'Рост не указан' : `${state.heightCm} см`;
    }
}

function updateStage(stage, form, configurator) {
    const state = readState(stage, form);

    updateMetricScale(stage, state);

    // SVG remains a deterministic local fallback until the 3D renderer is ready.
    renderPlayerCharacter(stage, state);
    updatePlayerCharacterThree(stage, state);

    stage.dispatchEvent(new CustomEvent('player-character:change', {
        bubbles: true,
        detail: state,
    }));

    return state;
}

function bindCharacterChoices(stage, form, configurator) {
    configurator.querySelectorAll('[data-player-character-choice]').forEach((button) => {
        button.addEventListener('click', () => {
            const field = button.dataset.playerCharacterChoice;
            const value = button.dataset.value;

            if (!field || !value) {
                return;
            }

            setCharacterField(form, configurator, field, value);
            updateStage(stage, form, configurator);
        });
    });
}

function bindPhysicalInputs(stage, form, configurator) {
    form.querySelectorAll('[data-player-character-input]').forEach((input) => {
        input.addEventListener('change', () => updateStage(stage, form, configurator));
        input.addEventListener('input', () => updateStage(stage, form, configurator));
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

    syncProfileGenderControls(stage, form, configurator);
    bindCharacterChoices(stage, form, configurator);
    bindPhysicalInputs(stage, form, configurator);

    const initialState = updateStage(stage, form, configurator);

    await waitUntilNearViewport(stage);

    const runtime = await mountPlayerCharacterThree(stage, initialState);

    if (runtime) {
        updatePlayerCharacterThree(stage, readState(stage, form));
    }
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-player-character-stage]').forEach(bindPlayerCharacterStage);
});
