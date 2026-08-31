const MORPH_NAMES = [
    'metric_h150_bmi17',
    'metric_h150_bmi23',
    'metric_h150_bmi38',
    'metric_h185_bmi17',
    'metric_h185_bmi38',
    'metric_h220_bmi17',
    'metric_h220_bmi23',
    'metric_h220_bmi38',
    'body_slim',
    'body_fat',
    'body_athletic',
    'body_muscle',
    'body_stocky',
];

const BODY_TYPE_MORPHS = {
    unspecified: {},
    slim: { body_slim: 0.25 },
    athletic: { body_athletic: 0.65 },
    muscular: { body_muscle: 0.72 },
    stocky: { body_stocky: 0.45 },
    large: { body_fat: 0.30, body_stocky: 0.12 },
};

const HEIGHT_NODES = [150, 185, 220];
const BMI_NODES = [17, 23, 38];

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

function interpolationSegment(nodes, rawValue) {
    const value = clamp(rawValue, nodes[0], nodes[nodes.length - 1]);
    const upperIndex = nodes.findIndex((node) => node >= value);
    const upper = nodes[Math.max(upperIndex, 0)];
    const lower = nodes[Math.max(upperIndex - 1, 0)];

    if (lower === upper) {
        return [{ value: lower, weight: 1 }];
    }

    const progress = (value - lower) / (upper - lower);
    return [
        { value: lower, weight: 1 - progress },
        { value: upper, weight: progress },
    ];
}

function metricMorphWeights(state) {
    const heightCm = clamp(Number(state.heightCm) || 185, 150, 220);
    const heightMeters = heightCm / 100;
    const weightKg = clamp(Number(state.weightKg) || 78, 40, 140);
    const bmi = weightKg / (heightMeters * heightMeters);
    const weights = {};

    interpolationSegment(HEIGHT_NODES, heightCm).forEach((heightNode) => {
        interpolationSegment(BMI_NODES, bmi).forEach((bmiNode) => {
            // h185/bmi23 is the Basis itself. Its interpolation share remains
            // implicit; all other nodes are deltas relative to that basis.
            if (heightNode.value === 185 && bmiNode.value === 23) {
                return;
            }

            weights[`metric_h${heightNode.value}_bmi${bmiNode.value}`] =
                heightNode.weight * bmiNode.weight;
        });
    });

    return weights;
}

function morphWeights(state) {
    const weights = Object.fromEntries(MORPH_NAMES.map((name) => [name, 0]));
    Object.assign(weights, metricMorphWeights(state));

    const bodyType = BODY_TYPE_MORPHS[state.bodyType] || BODY_TYPE_MORPHS.unspecified;
    Object.entries(bodyType).forEach(([name, value]) => {
        weights[name] = value;
    });

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
