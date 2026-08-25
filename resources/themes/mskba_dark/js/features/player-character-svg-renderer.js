const PLAYER_CHARACTER_MAX_HEIGHT_CM = 250;
const PLAYER_CHARACTER_FALLBACK_HEIGHT_CM = 180;

const BODY_METRICS = {
    unspecified: { frameWidth: 55, armScale: 1, legScale: 1 },
    slim: { frameWidth: 49, armScale: 0.9, legScale: 0.92 },
    athletic: { frameWidth: 55, armScale: 1.02, legScale: 1.02 },
    muscular: { frameWidth: 61, armScale: 1.13, legScale: 1.09 },
    stocky: { frameWidth: 62, armScale: 1.14, legScale: 1.16 },
    large: { frameWidth: 66, armScale: 1.19, legScale: 1.22 },
};

const SKIN_PALETTES = {
    porcelain: { base: '#f1c7a9', shadow: '#d4a184', highlight: '#ffd9bd' },
    light: { base: '#ddb08e', shadow: '#bd896a', highlight: '#efc8a7' },
    warm: { base: '#bd8360', shadow: '#986148', highlight: '#d9a17c' },
    tan: { base: '#9c6749', shadow: '#784934', highlight: '#b9805d' },
    brown: { base: '#704731', shadow: '#503022', highlight: '#8b6045' },
    deep: { base: '#432a22', shadow: '#2d1b17', highlight: '#624036' },
};

const HAIR_PALETTES = {
    black: { base: '#171513', shadow: '#080706' },
    dark_brown: { base: '#3a271f', shadow: '#211510' },
    brown: { base: '#694733', shadow: '#432b1e' },
    blond: { base: '#c9aa70', shadow: '#9f824f' },
    ginger: { base: '#9a4c2c', shadow: '#6d321d' },
    gray: { base: '#8c8b87', shadow: '#62615e' },
};

const UNIFORM_KITS = {
    mskba_home: {
        primary: '#161816',
        secondary: '#303430',
        accent: '#ef7d00',
        number: '#f5f1e8',
        shoe: '#171a18',
    },
    mskba_light: {
        primary: '#e7e3d9',
        secondary: '#c8c2b6',
        accent: '#ef7d00',
        number: '#191b19',
        shoe: '#e8e4da',
    },
    street_black: {
        primary: '#111312',
        secondary: '#2a2e2b',
        accent: '#d9ddd8',
        number: '#f5f5f1',
        shoe: '#111312',
    },
    city_night: {
        primary: '#121928',
        secondary: '#25324b',
        accent: '#f18a19',
        number: '#f3efe5',
        shoe: '#121722',
    },
};

function clamp(value, min, max) {
    return Math.min(max, Math.max(min, value));
}

export function parseNullableNumber(value) {
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

        return clamp((bmi - 23) * 0.52, -3.8, 7.8);
    }

    return clamp(((weightKg - 85) / 10) * 0.72, -3.8, 5.2);
}

function applyBodyMetrics(stage, state) {
    const base = BODY_METRICS[state.bodyType] || BODY_METRICS.unspecified;
    const weightModifier = calculateWeightModifier(state.heightCm, state.weightKg);
    const genderFrameAdjustment = state.gender === 'female' ? -1.8 : 0;
    const genderArmAdjustment = state.gender === 'female' ? -0.035 : 0;

    const frameWidth = clamp(base.frameWidth + genderFrameAdjustment + weightModifier * 1.25, 45, 74);
    const armScale = clamp(base.armScale + genderArmAdjustment + weightModifier * 0.025, 0.82, 1.38);
    const legScale = clamp(base.legScale + weightModifier * 0.022, 0.86, 1.42);

    stage.style.setProperty('--character-frame-width', `${frameWidth.toFixed(2)}%`);
    stage.style.setProperty('--character-arm-scale', armScale.toFixed(3));
    stage.style.setProperty('--character-leg-scale', legScale.toFixed(3));

    stage.dataset.bodyWidth = frameWidth.toFixed(2);
    stage.dataset.weightModifier = weightModifier.toFixed(2);
}

function applyPalette(stage, state) {
    const skin = SKIN_PALETTES[state.skinTone] || SKIN_PALETTES.warm;
    const hair = HAIR_PALETTES[state.hairColor] || HAIR_PALETTES.dark_brown;
    const uniform = UNIFORM_KITS[state.uniformKit] || UNIFORM_KITS.mskba_home;

    stage.style.setProperty('--skin-base', skin.base);
    stage.style.setProperty('--skin-shadow', skin.shadow);
    stage.style.setProperty('--skin-highlight', skin.highlight);
    stage.style.setProperty('--hair-base', hair.base);
    stage.style.setProperty('--hair-shadow', hair.shadow);
    stage.style.setProperty('--uniform-primary', uniform.primary);
    stage.style.setProperty('--uniform-secondary', uniform.secondary);
    stage.style.setProperty('--uniform-accent', uniform.accent);
    stage.style.setProperty('--uniform-number', uniform.number);
    stage.style.setProperty('--shoe-base', uniform.shoe);
}

function toggleMatchingLayers(stage, selector, attribute, activeValue) {
    stage.querySelectorAll(selector).forEach((layer) => {
        layer.hidden = layer.getAttribute(attribute) !== activeValue;
    });
}

function applyLayers(stage, state) {
    toggleMatchingLayers(stage, '[data-character-gender-layer]', 'data-character-gender-layer', state.gender);
    toggleMatchingLayers(stage, '[data-character-hairstyle]', 'data-character-hairstyle', state.hairstyle);
    toggleMatchingLayers(stage, '[data-character-uniform-pattern]', 'data-character-uniform-pattern', state.uniformKit);

    stage.querySelectorAll('[data-character-facial-hair]').forEach((layer) => {
        layer.hidden = state.gender !== 'male'
            || state.facialHair === 'none'
            || layer.dataset.characterFacialHair !== state.facialHair;
    });
}

export function renderPlayerCharacter(stage, state) {
    const previewHeightCm = state.heightCm ?? PLAYER_CHARACTER_FALLBACK_HEIGHT_CM;
    const normalizedHeightCm = clamp(previewHeightCm, 0, PLAYER_CHARACTER_MAX_HEIGHT_CM);
    const heightPercent = normalizedHeightCm / PLAYER_CHARACTER_MAX_HEIGHT_CM * 100;
    const heightLabel = stage.querySelector('[data-player-character-height-label]');

    stage.dataset.gender = state.gender;
    stage.dataset.height = state.heightCm ?? '';
    stage.dataset.weight = state.weightKg ?? '';
    stage.dataset.bodyType = state.bodyType || 'unspecified';
    stage.dataset.skinTone = state.skinTone;
    stage.dataset.hairstyle = state.hairstyle;
    stage.dataset.hairColor = state.hairColor;
    stage.dataset.facialHair = state.facialHair;
    stage.dataset.uniformKit = state.uniformKit;
    stage.dataset.hasHeight = state.heightCm === null ? 'false' : 'true';
    stage.style.setProperty('--player-height-percent', heightPercent.toFixed(2));

    applyBodyMetrics(stage, state);
    applyPalette(stage, state);
    applyLayers(stage, state);

    stage.setAttribute(
        'aria-label',
        state.heightCm === null
            ? 'Персонаж игрока на шкале роста, рост не указан'
            : `Персонаж игрока ростом ${state.heightCm} см на шкале до 250 см`,
    );

    if (heightLabel) {
        heightLabel.textContent = state.heightCm === null ? 'Рост не указан' : `${state.heightCm} см`;
    }
}
