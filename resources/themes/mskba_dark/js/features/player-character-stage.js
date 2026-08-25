const PLAYER_CHARACTER_MAX_HEIGHT_CM = 250;
const PLAYER_CHARACTER_FALLBACK_HEIGHT_CM = 180;

const PLAYER_CHARACTER_BODY_METRICS = {
    unspecified: {
        bodyWidth: 28,
        headWidth: 34,
        torsoWidth: 58,
        armWidth: 14,
        legWidth: 18,
    },
    slim: {
        bodyWidth: 23,
        headWidth: 33,
        torsoWidth: 53,
        armWidth: 12,
        legWidth: 16,
    },
    athletic: {
        bodyWidth: 27,
        headWidth: 34,
        torsoWidth: 60,
        armWidth: 14,
        legWidth: 18,
    },
    muscular: {
        bodyWidth: 31,
        headWidth: 34,
        torsoWidth: 66,
        armWidth: 16,
        legWidth: 20,
    },
    stocky: {
        bodyWidth: 33,
        headWidth: 35,
        torsoWidth: 63,
        armWidth: 17,
        legWidth: 22,
    },
    large: {
        bodyWidth: 36,
        headWidth: 35,
        torsoWidth: 67,
        armWidth: 18,
        legWidth: 23,
    },
};

function clamp(value, min, max) {
    return Math.min(max, Math.max(min, value));
}

function parseNullableNumber(value) {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    const number = Number(value);

    return Number.isFinite(number) ? number : null;
}

function calculateWeightModifier(heightCm, weightKg) {
    if (weightKg === null) {
        return 0;
    }

    if (heightCm !== null && heightCm > 0) {
        const heightMeters = heightCm / 100;
        const bmi = weightKg / (heightMeters * heightMeters);

        return clamp((bmi - 23) * 0.5, -3.5, 7.5);
    }

    return clamp(((weightKg - 85) / 10) * 0.7, -3.5, 5);
}

function applyBodyMetrics(stage, heightCm, weightKg) {
    const bodyType = stage.dataset.bodyType || 'unspecified';
    const base = PLAYER_CHARACTER_BODY_METRICS[bodyType]
        || PLAYER_CHARACTER_BODY_METRICS.unspecified;
    const weightModifier = calculateWeightModifier(heightCm, weightKg);

    const bodyWidth = clamp(base.bodyWidth + weightModifier, 19, 44);
    const headWidth = clamp(base.headWidth + weightModifier * 0.12, 31, 39);
    const torsoWidth = clamp(base.torsoWidth + weightModifier * 1.15, 48, 76);
    const armWidth = clamp(base.armWidth + weightModifier * 0.38, 10, 22);
    const legWidth = clamp(base.legWidth + weightModifier * 0.46, 14, 28);

    stage.style.setProperty('--player-body-width', `${bodyWidth.toFixed(2)}%`);
    stage.style.setProperty('--player-head-width', `${headWidth.toFixed(2)}%`);
    stage.style.setProperty('--player-torso-width', `${torsoWidth.toFixed(2)}%`);
    stage.style.setProperty('--player-arm-width', `${armWidth.toFixed(2)}%`);
    stage.style.setProperty('--player-leg-width', `${legWidth.toFixed(2)}%`);

    stage.dataset.bodyWidth = bodyWidth.toFixed(2);
    stage.dataset.weightModifier = weightModifier.toFixed(2);
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

    applyBodyMetrics(stage, heightCm, weightKg);

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
