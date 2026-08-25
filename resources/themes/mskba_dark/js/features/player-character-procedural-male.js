const DEFAULT_HEIGHT_CM = 180;
const DEFAULT_WEIGHT_KG = 80;
const FIELD_ISOLATION = 72;
const FIELD_SUBTRACT = 12;

const TYPE_PROFILE = {
    unspecified: { shoulder: 0, chest: 0, waist: 0, hips: 0, arms: 0, legs: 0 },
    slim: { shoulder: -0.06, chest: -0.08, waist: -0.09, hips: -0.06, arms: -0.10, legs: -0.08 },
    athletic: { shoulder: 0.04, chest: 0.04, waist: -0.03, hips: 0, arms: 0.03, legs: 0.04 },
    muscular: { shoulder: 0.13, chest: 0.12, waist: 0.01, hips: 0.03, arms: 0.16, legs: 0.14 },
    stocky: { shoulder: 0.08, chest: 0.10, waist: 0.12, hips: 0.10, arms: 0.10, legs: 0.10 },
    large: { shoulder: 0.10, chest: 0.15, waist: 0.22, hips: 0.16, arms: 0.13, legs: 0.14 },
};

const SKIN_TONES = {
    porcelain: '#f1c7a9',
    light: '#ddb08e',
    warm: '#bd8360',
    tan: '#9c6749',
    brown: '#704731',
    deep: '#432a22',
};

const UNIFORM_TONES = {
    mskba_home: { primary: '#151816', secondary: '#202520', accent: '#ef7d00' },
    mskba_light: { primary: '#e8e4d9', secondary: '#f5f1e7', accent: '#ef7d00' },
    street_black: { primary: '#101211', secondary: '#252a27', accent: '#d9ddd8' },
    city_night: { primary: '#101827', secondary: '#19253a', accent: '#f18a19' },
};

function clamp(value, min, max) {
    return Math.min(max, Math.max(min, value));
}

function profileForState(state) {
    const heightCm = Number(state.heightCm) || DEFAULT_HEIGHT_CM;
    const weightKg = Number(state.weightKg) || DEFAULT_WEIGHT_KG;
    const heightMeters = clamp(heightCm / 100, 1.45, 2.5);
    const bmi = weightKg / (heightMeters * heightMeters);
    const mass = clamp((bmi - 22.5) / 13, -0.65, 1);
    const type = TYPE_PROFILE[state.bodyType] || TYPE_PROFILE.unspecified;

    const softMass = Math.max(0, mass);
    const leanMass = Math.min(0, mass);

    return {
        mass,
        shoulder: clamp(1 + type.shoulder + mass * 0.06, 0.82, 1.24),
        chest: clamp(1 + type.chest + mass * 0.08, 0.80, 1.30),
        waist: clamp(1 + type.waist + softMass * 0.20 + leanMass * 0.07, 0.76, 1.38),
        hips: clamp(1 + type.hips + mass * 0.10, 0.82, 1.28),
        arms: clamp(1 + type.arms + mass * 0.09, 0.76, 1.32),
        legs: clamp(1 + type.legs + mass * 0.10, 0.78, 1.34),
        depth: clamp(1 + mass * 0.11 + type.chest * 0.30, 0.84, 1.28),
    };
}

function fieldStrengthForRadius(radius) {
    return (FIELD_ISOLATION + FIELD_SUBTRACT) * radius * radius;
}

function addBall(effect, x, y, z, radius, strengthMultiplier = 1) {
    effect.addBall(
        x,
        y,
        z,
        fieldStrengthForRadius(radius) * strengthMultiplier,
        FIELD_SUBTRACT,
    );
}

function addSegment(effect, from, to, radiusFrom, radiusTo, steps = 6) {
    for (let index = 0; index <= steps; index += 1) {
        const t = index / steps;
        const ease = t * t * (3 - 2 * t);
        addBall(
            effect,
            from[0] + (to[0] - from[0]) * t,
            from[1] + (to[1] - from[1]) * t,
            from[2] + (to[2] - from[2]) * t,
            radiusFrom + (radiusTo - radiusFrom) * ease,
        );
    }
}

function addMaleBodyField(effect, profile) {
    effect.reset();
    effect.isolation = FIELD_ISOLATION;

    // Head and face volumes. The slightly forward jaw/nose keep the head from
    // reading as a sphere while still remaining a neutral avatar base.
    addBall(effect, 0.500, 0.910, 0.500, 0.078);
    addBall(effect, 0.500, 0.932, 0.500, 0.065);
    addBall(effect, 0.500, 0.878, 0.512, 0.061);
    addBall(effect, 0.500, 0.896, 0.570, 0.021, 0.86);
    addBall(effect, 0.448, 0.904, 0.502, 0.022, 0.74);
    addBall(effect, 0.552, 0.904, 0.502, 0.022, 0.74);

    // Neck and trapezius.
    addSegment(effect, [0.500, 0.842, 0.495], [0.500, 0.805, 0.495], 0.037, 0.051, 4);
    addBall(effect, 0.448, 0.797, 0.493, 0.049 * profile.shoulder);
    addBall(effect, 0.552, 0.797, 0.493, 0.049 * profile.shoulder);

    // Ribcage / pectorals / upper back. Multiple overlapping masses give a
    // smooth thoracic shape instead of a cylinder.
    addBall(effect, 0.500, 0.718, 0.496, 0.112 * profile.chest);
    addBall(effect, 0.423, 0.742, 0.516, 0.077 * profile.chest);
    addBall(effect, 0.577, 0.742, 0.516, 0.077 * profile.chest);
    addBall(effect, 0.500, 0.688, 0.470, 0.092 * profile.depth);
    addBall(effect, 0.500, 0.635, 0.500, 0.086 * profile.waist);
    addBall(effect, 0.500, 0.585, 0.500, 0.076 * profile.waist);

    // Pelvis / gluteal masses.
    addBall(effect, 0.500, 0.530, 0.493, 0.083 * profile.hips);
    addBall(effect, 0.448, 0.515, 0.478, 0.066 * profile.hips);
    addBall(effect, 0.552, 0.515, 0.478, 0.066 * profile.hips);

    // Deltoids are distinct anatomical volumes but merge into the torso and
    // upper arm in the implicit surface, so there are no visible ball joints.
    const shoulderX = 0.500 + 0.158 * profile.shoulder;
    addBall(effect, shoulderX, 0.748, 0.500, 0.058 * profile.shoulder);
    addBall(effect, 1 - shoulderX, 0.748, 0.500, 0.058 * profile.shoulder);

    const armRadius = 0.040 * profile.arms;
    const forearmRadius = 0.034 * profile.arms;

    addSegment(effect, [shoulderX, 0.733, 0.500], [shoulderX + 0.035, 0.595, 0.514], armRadius * 1.16, armRadius, 7);
    addBall(effect, shoulderX + 0.038, 0.574, 0.516, 0.035 * profile.arms);
    addSegment(effect, [shoulderX + 0.038, 0.560, 0.516], [shoulderX + 0.026, 0.425, 0.532], forearmRadius * 1.10, forearmRadius * 0.82, 7);
    addSegment(effect, [shoulderX + 0.025, 0.416, 0.532], [shoulderX + 0.020, 0.365, 0.542], 0.028 * profile.arms, 0.023 * profile.arms, 3);
    addBall(effect, shoulderX + 0.005, 0.350, 0.551, 0.029 * profile.arms);
    addBall(effect, shoulderX - 0.012, 0.343, 0.558, 0.018 * profile.arms, 0.78);

    const leftShoulderX = 1 - shoulderX;
    addSegment(effect, [leftShoulderX, 0.733, 0.500], [leftShoulderX - 0.035, 0.595, 0.514], armRadius * 1.16, armRadius, 7);
    addBall(effect, leftShoulderX - 0.038, 0.574, 0.516, 0.035 * profile.arms);
    addSegment(effect, [leftShoulderX - 0.038, 0.560, 0.516], [leftShoulderX - 0.026, 0.425, 0.532], forearmRadius * 1.10, forearmRadius * 0.82, 7);
    addSegment(effect, [leftShoulderX - 0.025, 0.416, 0.532], [leftShoulderX - 0.020, 0.365, 0.542], 0.028 * profile.arms, 0.023 * profile.arms, 3);
    addBall(effect, leftShoulderX - 0.005, 0.350, 0.551, 0.029 * profile.arms);
    addBall(effect, leftShoulderX + 0.012, 0.343, 0.558, 0.018 * profile.arms, 0.78);

    // Legs: separate thigh, knee, calf and ankle volumes blended continuously.
    const thighX = 0.448;
    const thighRadius = 0.055 * profile.legs;
    const calfRadius = 0.044 * profile.legs;

    addSegment(effect, [thighX, 0.500, 0.498], [thighX, 0.332, 0.510], thighRadius * 1.18, thighRadius, 8);
    addBall(effect, thighX, 0.303, 0.520, 0.045 * profile.legs);
    addSegment(effect, [thighX, 0.286, 0.510], [thighX - 0.005, 0.155, 0.492], calfRadius * 1.12, calfRadius * 0.78, 7);
    addBall(effect, thighX - 0.006, 0.130, 0.494, 0.031 * profile.legs);
    addSegment(effect, [thighX - 0.006, 0.120, 0.500], [thighX - 0.004, 0.078, 0.515], 0.027 * profile.legs, 0.022 * profile.legs, 3);

    const rightThighX = 1 - thighX;
    addSegment(effect, [rightThighX, 0.500, 0.498], [rightThighX, 0.332, 0.510], thighRadius * 1.18, thighRadius, 8);
    addBall(effect, rightThighX, 0.303, 0.520, 0.045 * profile.legs);
    addSegment(effect, [rightThighX, 0.286, 0.510], [rightThighX + 0.005, 0.155, 0.492], calfRadius * 1.12, calfRadius * 0.78, 7);
    addBall(effect, rightThighX + 0.006, 0.130, 0.494, 0.031 * profile.legs);
    addSegment(effect, [rightThighX + 0.006, 0.120, 0.500], [rightThighX + 0.004, 0.078, 0.515], 0.027 * profile.legs, 0.022 * profile.legs, 3);

    // Feet project forward (+Z) and have a heel/arch/toe progression rather
    // than one capsule. They deliberately stay inside the metric floor plane.
    for (const x of [thighX - 0.004, rightThighX + 0.004]) {
        addBall(effect, x, 0.062, 0.522, 0.027 * profile.legs);
        addBall(effect, x, 0.055, 0.552, 0.031 * profile.legs);
        addBall(effect, x, 0.050, 0.585, 0.030 * profile.legs);
        addBall(effect, x, 0.047, 0.615, 0.026 * profile.legs);
    }

    effect.update();
}

function createMaterial(THREE, color, options = {}) {
    return new THREE.MeshPhysicalMaterial({
        color,
        roughness: options.roughness ?? 0.68,
        metalness: options.metalness ?? 0,
        clearcoat: options.clearcoat ?? 0.04,
        clearcoatRoughness: options.clearcoatRoughness ?? 0.72,
        sheen: options.sheen ?? 0.08,
        sheenRoughness: 0.8,
        side: THREE.DoubleSide,
    });
}

function makeEllipsoid(THREE, radius, scale, material, position) {
    const mesh = new THREE.Mesh(new THREE.SphereGeometry(radius, 28, 20), material);
    mesh.scale.set(scale[0], scale[1], scale[2]);
    mesh.position.set(position[0], position[1], position[2]);
    mesh.castShadow = true;
    mesh.receiveShadow = true;
    return mesh;
}

function createUniform(THREE, profile, state) {
    const group = new THREE.Group();
    group.name = 'MSKBA_ProceduralUniform';

    const kit = UNIFORM_TONES[state.uniformKit] || UNIFORM_TONES.mskba_home;
    const jerseyMaterial = createMaterial(THREE, kit.primary, { roughness: 0.78, sheen: 0.18 });
    const accentMaterial = createMaterial(THREE, kit.accent, { roughness: 0.72, sheen: 0.12 });
    const shortsMaterial = createMaterial(THREE, kit.secondary, { roughness: 0.80, sheen: 0.14 });
    const shoeMaterial = createMaterial(THREE, '#111412', { roughness: 0.58 });
    const soleMaterial = createMaterial(THREE, kit.accent, { roughness: 0.60 });

    jerseyMaterial.userData.playerCharacterRole = 'uniform';
    accentMaterial.userData.playerCharacterRole = 'uniform-accent';
    shortsMaterial.userData.playerCharacterRole = 'uniform-secondary';
    shoeMaterial.userData.playerCharacterRole = 'shoe';
    soleMaterial.userData.playerCharacterRole = 'shoe-accent';

    // Soft jersey shell. It is intentionally simple in v1; the body underneath
    // provides the anatomical silhouette while the shell proves the equipment layer.
    const jersey = new THREE.Mesh(
        new THREE.CylinderGeometry(0.205, 0.165, 0.425, 40, 4, true),
        jerseyMaterial,
    );
    jersey.name = 'Procedural_Jersey';
    jersey.scale.set(profile.chest, 1, 0.58 * profile.depth);
    jersey.position.set(0, 0.347, 0.018);
    jersey.castShadow = true;
    group.add(jersey);

    const collar = new THREE.Mesh(new THREE.TorusGeometry(0.073, 0.010, 10, 36), accentMaterial);
    collar.name = 'Procedural_Jersey_Collar';
    collar.scale.set(1.15 * profile.chest, 1, 0.68);
    collar.rotation.x = Math.PI / 2;
    collar.position.set(0, 0.550, 0.105);
    group.add(collar);

    const shortWaist = new THREE.Mesh(
        new THREE.CylinderGeometry(0.170, 0.182, 0.195, 36, 2, true),
        shortsMaterial,
    );
    shortWaist.name = 'Procedural_Shorts_Waist';
    shortWaist.scale.set(profile.hips, 1, 0.64 * profile.depth);
    shortWaist.position.set(0, 0.005, 0.010);
    shortWaist.castShadow = true;
    group.add(shortWaist);

    for (const x of [-0.092, 0.092]) {
        const leg = new THREE.Mesh(
            new THREE.CylinderGeometry(0.078, 0.092, 0.205, 28, 2, true),
            shortsMaterial,
        );
        leg.name = 'Procedural_Shorts_Leg';
        leg.scale.set(profile.legs, 1, 0.72 * profile.depth);
        leg.position.set(x, -0.085, 0.020);
        leg.castShadow = true;
        group.add(leg);
    }

    // Shoes are separate equipment meshes so they can later be swapped entirely.
    for (const x of [-0.105, 0.105]) {
        const shoe = makeEllipsoid(THREE, 0.095, [0.72, 0.34, 1.30], shoeMaterial, [x, -0.438, 0.105]);
        shoe.name = 'Procedural_Shoe';
        group.add(shoe);

        const sole = makeEllipsoid(THREE, 0.096, [0.73, 0.12, 1.34], soleMaterial, [x, -0.466, 0.110]);
        sole.name = 'Procedural_Shoe_Sole';
        group.add(sole);
    }

    return group;
}

function mapFieldCoordinate(value) {
    return value * 2 - 1;
}

function createFaceDetails(THREE, skinMaterial) {
    const group = new THREE.Group();
    group.name = 'MSKBA_ProceduralFaceDetails';

    const dark = createMaterial(THREE, '#34251f', { roughness: 0.82, clearcoat: 0 });

    for (const x of [0.475, 0.525]) {
        const eye = makeEllipsoid(
            THREE,
            0.017,
            [1.0, 0.58, 0.36],
            dark,
            [mapFieldCoordinate(x), mapFieldCoordinate(0.910), 0.154],
        );
        eye.name = 'Procedural_Eye';
        group.add(eye);
    }

    const nose = makeEllipsoid(THREE, 0.026, [0.62, 1.08, 0.78], skinMaterial, [0, 0.790, 0.180]);
    nose.name = 'Procedural_Nose';
    group.add(nose);

    const mouth = new THREE.Mesh(new THREE.BoxGeometry(0.055, 0.006, 0.006), dark);
    mouth.name = 'Procedural_Mouth';
    mouth.position.set(0, 0.735, 0.181);
    group.add(mouth);

    return group;
}

export function createProceduralMalePlayer(engine, state) {
    const { THREE, MarchingCubes } = engine;
    const profile = profileForState(state);
    const group = new THREE.Group();
    group.name = 'MSKBA_ProceduralMale_v1';

    const skinMaterial = createMaterial(
        THREE,
        SKIN_TONES[state.skinTone] || SKIN_TONES.warm,
        { roughness: 0.76, clearcoat: 0.02, sheen: 0.04 },
    );
    skinMaterial.userData.playerCharacterRole = 'skin';

    const resolution = window.matchMedia?.('(max-width: 600px)').matches ? 52 : 60;
    const body = new MarchingCubes(resolution, skinMaterial, false, false, 160000);
    body.name = 'MSKBA_ProceduralMale_Body';
    body.isolation = FIELD_ISOLATION;
    body.enableUvs = false;
    body.enableColors = false;
    body.castShadow = true;
    body.receiveShadow = true;
    body.frustumCulled = false;

    addMaleBodyField(body, profile);
    group.add(body);

    const uniform = createUniform(THREE, profile, state);
    uniform.position.y = 0.135;
    group.add(uniform);

    const face = createFaceDetails(THREE, skinMaterial);
    group.add(face);

    group.userData.proceduralBody = body;
    group.userData.proceduralUniform = uniform;
    group.userData.proceduralProfile = profile;
    group.userData.playerCharacterBase = 'procedural-male-v1';

    return group;
}

export function updateProceduralMalePlayer(engine, model, state) {
    const body = model?.userData?.proceduralBody;

    if (!body) {
        return false;
    }

    const profile = profileForState(state);
    addMaleBodyField(body, profile);
    model.userData.proceduralProfile = profile;

    const skinColor = SKIN_TONES[state.skinTone] || SKIN_TONES.warm;
    if (body.material?.color) {
        body.material.color.set(skinColor);
        body.material.needsUpdate = true;
    }

    // Rebuild the lightweight equipment layer when body proportions or kit change.
    const previousUniform = model.userData.proceduralUniform;
    if (previousUniform) {
        model.remove(previousUniform);
        previousUniform.traverse((object) => {
            object.geometry?.dispose?.();
            const materials = Array.isArray(object.material) ? object.material : [object.material];
            materials.filter(Boolean).forEach((material) => material.dispose?.());
        });
    }

    const uniform = createUniform(engine.THREE, profile, state);
    uniform.position.y = 0.135;
    model.add(uniform);
    model.userData.proceduralUniform = uniform;

    return true;
}
