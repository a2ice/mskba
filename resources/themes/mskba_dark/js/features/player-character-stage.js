import { parseNullableNumber, renderPlayerCharacter } from './player-character-svg-renderer.js';

const DEFAULT_HAIRSTYLE = {
    male: 'male_fade',
    female: 'female_ponytail',
};

function characterField(form, key) {
    return form.querySelector(`[data-player-character-field="${key}"]`);
}

function readState(stage, form) {
    return {
        gender: characterField(form, 'gender')?.value || stage.dataset.gender || 'male',
        heightCm: parseNullableNumber(form.querySelector('[data-player-character-input="height"]')?.value),
        weightKg: parseNullableNumber(form.querySelector('[data-player-character-input="weight"]')?.value),
        bodyType: form.querySelector('[data-player-character-input="body-type"]')?.value || 'unspecified',
        skinTone: characterField(form, 'skin-tone')?.value || 'warm',
        hairstyle: characterField(form, 'hairstyle')?.value || 'male_fade',
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

function syncGenderDependentControls(form, configurator, gender) {
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

function updateStage(stage, form, configurator) {
    const state = readState(stage, form);

    renderPlayerCharacter(stage, state);

    stage.dispatchEvent(new CustomEvent('player-character:change', {
        bubbles: true,
        detail: state,
    }));
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

            if (field === 'gender') {
                syncGenderDependentControls(form, configurator, value);
            }

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

function bindPlayerCharacterStage(stage) {
    const form = stage.closest('form');
    const configurator = form?.querySelector('[data-player-character-configurator]');

    if (!form || !configurator) {
        return;
    }

    const gender = characterField(form, 'gender')?.value || stage.dataset.gender || 'male';

    syncGenderDependentControls(form, configurator, gender);
    bindCharacterChoices(stage, form, configurator);
    bindPhysicalInputs(stage, form, configurator);
    updateStage(stage, form, configurator);
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-player-character-stage]').forEach(bindPlayerCharacterStage);
});
