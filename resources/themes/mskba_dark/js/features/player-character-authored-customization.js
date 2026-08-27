const MORPH_NAMES = [
    'body_slim',
    'body_heavy',
    'body_muscular',
    'body_stocky',
];

const BODY_TYPE_MORPHS = {
    unspecified: {},
    slim: { body_slim: 0.72 },
    athletic: { body_muscular: 0.28 },
    muscular: { body_muscular: 0.88 },
    stocky: { body_stocky: 0.82, body_heavy: 0.12 },
    large: { body_heavy: 0.78, body_stocky: 0.36 },
};

const HAIR_TONES = {
    black: '#171513',
    dark_brown: '#3a271f',
    brown: '#694733',
    blond: '#c9aa70',
    ginger: '#9a4c2c',
    gray: '#8c8b87',
};

const HAIR_STYLE_NODES = {
    male_fade: 'MSKBA_Hair_Male_Fade',
    female_ponytail: 'MSKBA_Hair_Female_Ponytail',
};

const FACIAL_HAIR_NODES = {
    short_beard: 'MSKBA_Beard_Short',
};

function clamp(value, min, max) {
    return Math.min(max, Math.max(min, value));
}

function morphWeights(state) {
    const weights = Object.fromEntries(MORPH_NAMES.map((name) => [name, 0]));
    const bodyType = BODY_TYPE_MORPHS[state.bodyType] || BODY_TYPE_MORPHS.unspecified;
    Object.entries(bodyType).forEach(([name, value]) => {
        weights[name] = value;
    });

    const heightMeters = Math.max((Number(state.heightCm) || 180) / 100, 1.45);
    const weightKg = Number(state.weightKg) || 78;
    const bmiDelta = clamp((weightKg / (heightMeters * heightMeters) - 22.5) / 12, -1, 1);

    if (bmiDelta < 0) {
        weights.body_slim = clamp(weights.body_slim + Math.abs(bmiDelta) * 0.58, 0, 1);
    } else {
        weights.body_heavy = clamp(weights.body_heavy + bmiDelta * 0.62, 0, 1);
    }

    return weights;
}

function setMorphWeights(model, weights) {
    model?.traverse((object) => {
        if (!object.isMesh || !object.morphTargetDictionary || !object.morphTargetInfluences) {
            return;
        }

        MORPH_NAMES.forEach((name) => {
            const index = object.morphTargetDictionary[name];
            if (index !== undefined) {
                object.morphTargetInfluences[index] = weights[name] || 0;
            }
        });
    });
}

function isStyleRoot(object, prefix) {
    return !object.isMesh && object.name.startsWith(prefix);
}

function updateStyleVisibility(model, state) {
    const hairNodeName = HAIR_STYLE_NODES[state.hairstyle] || '';
    const beardNodeName = state.gender === 'female' || state.facialHair === 'none'
        ? ''
        : FACIAL_HAIR_NODES[state.facialHair] || '';

    model?.traverse((object) => {
        if (isStyleRoot(object, 'MSKBA_Hair_')) {
            object.visible = object.name === hairNodeName;
        }
        if (isStyleRoot(object, 'MSKBA_Beard_')) {
            object.visible = object.name === beardNodeName;
        }
    });
}

function updateHairMaterials(runtime, state) {
    const color = new runtime.THREE.Color(HAIR_TONES[state.hairColor] || HAIR_TONES.dark_brown);

    runtime.model?.traverse((object) => {
        if (!object.isMesh) {
            return;
        }

        const materials = Array.isArray(object.material) ? object.material : [object.material];
        materials.filter(Boolean).forEach((material) => {
            if (!['hair', 'beard'].includes(material.userData.playerCharacterRole)) {
                return;
            }

            material.color.copy(color);
            material.needsUpdate = true;
        });
    });
}

export function applyAuthoredBodyShape(runtime, state) {
    if (!runtime?.model) {
        return;
    }

    const weights = morphWeights(state);
    setMorphWeights(runtime.model, weights);
    runtime.bodyMorphWeights = weights;
}

export function updateAuthoredAccessories(runtime, state) {
    if (!runtime?.model) {
        return;
    }

    updateStyleVisibility(runtime.model, state);
    updateHairMaterials(runtime, state);
}

export function destroyAuthoredAccessories(runtime) {
    runtime.bodyMorphWeights = null;
}
