const PLAYER_CHARACTER_MAX_HEIGHT_CM = 250;
const PLAYER_CHARACTER_FALLBACK_HEIGHT_CM = 180;

function parseNullableNumber(value) {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    const number = Number(value);

    return Number.isFinite(number) ? number : null;
}

function updateStage(stage, form) {
    form.querySelectorAll('[data-player-character-input]').forEach((input) => {
        const key = input.dataset.playerCharacterInput;

        if (!key) {
            return;
        }

        stage.setAttribute(`data-${key}`, input.value || (key === 'body-type' ? 'unspecified' : ''));
    });

    const heightCm = parseNullableNumber(stage.dataset.height);
    const weightKg = parseNullableNumber(stage.dataset.weight);
    const previewHeightCm = heightCm ?? PLAYER_CHARACTER_FALLBACK_HEIGHT_CM;
    const normalizedHeightCm = Math.min(PLAYER_CHARACTER_MAX_HEIGHT_CM, Math.max(0, previewHeightCm));
    const heightPercent = normalizedHeightCm / PLAYER_CHARACTER_MAX_HEIGHT_CM * 100;
    const heightLabel = stage.querySelector('[data-player-character-height-label]');

    stage.dataset.hasHeight = heightCm === null ? 'false' : 'true';
    stage.style.setProperty('--player-height-percent', heightPercent.toFixed(2));
    stage.setAttribute(
        'aria-label',
        heightCm === null
            ? 'Персонаж игрока на шкале роста, рост не указан'
            : `Персонаж игрока ростом ${heightCm} см на шкале до 250 см`,
    );

    if (heightLabel) {
        heightLabel.textContent = heightCm === null ? 'Рост не указан' : `${heightCm} см`;
    }

    stage.dispatchEvent(new CustomEvent('player-character:change', {
        bubbles: true,
        detail: {
            gender: stage.dataset.gender || null,
            heightCm,
            weightKg,
            bodyType: stage.dataset.bodyType && stage.dataset.bodyType !== 'unspecified'
                ? stage.dataset.bodyType
                : null,
            skinTone: stage.dataset.skinTone || null,
            hairstyle: stage.dataset.hairstyle || null,
        },
    }));
}

function bindPlayerCharacterStage(stage) {
    const form = stage.closest('form');

    if (!form) {
        return;
    }

    const inputs = form.querySelectorAll('[data-player-character-input]');

    inputs.forEach((input) => {
        input.addEventListener('change', () => updateStage(stage, form));
        input.addEventListener('input', () => updateStage(stage, form));
    });

    updateStage(stage, form);
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-player-character-stage]').forEach(bindPlayerCharacterStage);
});
