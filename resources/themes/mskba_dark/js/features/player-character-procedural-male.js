const DEFAULT_HEIGHT_CM = 180;
const DEFAULT_WEIGHT_KG = 80;
const BODY_CROWN_Y = 1.79;
const FLOOR_Y = 0;

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

const HAIR_TONES = {
    black: '#171513',
    dark_brown: '#3a271f',
    brown: '#694733',
    blond: '#c9aa70',
    ginger: '#9a4c2c',
    gray: '#8c8b87',
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
        shoulder: clamp(1 + type.shoulder + mass * 0.055, 0.82, 1.24),
        chest: clamp(1 + type.chest + mass * 0.075, 0.80, 1.30),
        waist: clamp(1 + type.waist + softMass * 0.18 + leanMass * 0.06, 0.76, 1.38),
        hips: clamp(1 + type.hips + mass * 0.09, 0.82, 1.28),
        arms: clamp(1 + type.arms + mass * 0.085, 0.76, 1.32),
        legs: clamp(1 + type.legs + mass * 0.09, 0.78, 1.34),
        depth: clamp(1 + mass * 0.10 + type.chest * 0.28, 0.84, 1.28),
    };
}

function createMaterial(THREE, color, options = {}) {
    return new THREE.MeshPhysicalMaterial({
        color,
        roughness: options.roughness ?? 0.70,
        metalness: options.metalness ?? 0,
        clearcoat: options.clearcoat ?? 0.025,
        clearcoatRoughness: options.clearcoatRoughness ?? 0.78,
        sheen: options.sheen ?? 0.06,
        sheenRoughness: 0.82,
        side: THREE.DoubleSide,
    });
}

function createLoftYGeometry(THREE, rings, radialSegments = 36, capBottom = true, capTop = true) {
    const positions = [];
    const indices = [];

    rings.forEach((ring) => {
        for (let segment = 0; segment < radialSegments; segment += 1) {
            const angle = segment / radialSegments * Math.PI * 2;
            positions.push(
                (ring.cx || 0) + ring.rx * Math.cos(angle),
                ring.y,
                (ring.cz || 0) + ring.rz * Math.sin(angle),
            );
        }
    });

    for (let ringIndex = 0; ringIndex < rings.length - 1; ringIndex += 1) {
        for (let segment = 0; segment < radialSegments; segment += 1) {
            const next = (segment + 1) % radialSegments;
            const a = ringIndex * radialSegments + segment;
            const b = ringIndex * radialSegments + next;
            const c = (ringIndex + 1) * radialSegments + next;
            const d = (ringIndex + 1) * radialSegments + segment;
            indices.push(a, b, c, a, c, d);
        }
    }

    if (capBottom) {
        const ring = rings[0];
        const center = positions.length / 3;
        positions.push(ring.cx || 0, ring.y, ring.cz || 0);
        for (let segment = 0; segment < radialSegments; segment += 1) {
            indices.push(center, (segment + 1) % radialSegments, segment);
        }
    }

    if (capTop) {
        const ring = rings[rings.length - 1];
        const center = positions.length / 3;
        const offset = (rings.length - 1) * radialSegments;
        positions.push(ring.cx || 0, ring.y, ring.cz || 0);
        for (let segment = 0; segment < radialSegments; segment += 1) {
            const next = (segment + 1) % radialSegments;
            indices.push(center, offset + segment, offset + next);
        }
    }

    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.Float32BufferAttribute(positions, 3));
    geometry.setIndex(indices);
    geometry.computeVertexNormals();
    geometry.computeBoundingBox();
    geometry.computeBoundingSphere();
    return geometry;
}

function createPathLoftGeometry(THREE, points, radialSegments = 24) {
    const positions = [];
    const indices = [];
    const centers = points.map((point) => new THREE.Vector3(point.x, point.y, point.z));
    let previousNormal = null;

    points.forEach((point, index) => {
        let tangent;

        if (index === 0) {
            tangent = centers[1].clone().sub(centers[0]);
        } else if (index === points.length - 1) {
            tangent = centers[index].clone().sub(centers[index - 1]);
        } else {
            tangent = centers[index + 1].clone().sub(centers[index - 1]);
        }

        tangent.normalize();

        let reference = new THREE.Vector3(0, 0, 1);
        if (Math.abs(tangent.dot(reference)) > 0.9) {
            reference = new THREE.Vector3(1, 0, 0);
        }

        let normal = new THREE.Vector3().crossVectors(tangent, reference).normalize();
        let binormal = new THREE.Vector3().crossVectors(tangent, normal).normalize();

        if (previousNormal && normal.dot(previousNormal) < 0) {
            normal.multiplyScalar(-1);
            binormal.multiplyScalar(-1);
        }
        previousNormal = normal.clone();

        for (let segment = 0; segment < radialSegments; segment += 1) {
            const angle = segment / radialSegments * Math.PI * 2;
            const vertex = centers[index].clone()
                .addScaledVector(normal, Math.cos(angle) * point.ra)
                .addScaledVector(binormal, Math.sin(angle) * point.rb);
            positions.push(vertex.x, vertex.y, vertex.z);
        }
    });

    for (let ringIndex = 0; ringIndex < points.length - 1; ringIndex += 1) {
        for (let segment = 0; segment < radialSegments; segment += 1) {
            const next = (segment + 1) % radialSegments;
            const a = ringIndex * radialSegments + segment;
            const b = ringIndex * radialSegments + next;
            const c = (ringIndex + 1) * radialSegments + next;
            const d = (ringIndex + 1) * radialSegments + segment;
            indices.push(a, b, c, a, c, d);
        }
    }

    for (const [ringIndex, reverse] of [[0, true], [points.length - 1, false]]) {
        const point = points[ringIndex];
        const center = positions.length / 3;
        const offset = ringIndex * radialSegments;
        positions.push(point.x, point.y, point.z);
        for (let segment = 0; segment < radialSegments; segment += 1) {
            const next = (segment + 1) % radialSegments;
            if (reverse) {
                indices.push(center, offset + next, offset + segment);
            } else {
                indices.push(center, offset + segment, offset + next);
            }
        }
    }

    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.Float32BufferAttribute(positions, 3));
    geometry.setIndex(indices);
    geometry.computeVertexNormals();
    geometry.computeBoundingBox();
    geometry.computeBoundingSphere();
    return geometry;
}

function createJerseyPanelGeometry(THREE, profile, front = true) {
    const side = 0.205 * profile.chest + 0.021;
    const shoulderOuter = 0.188 * profile.shoulder;
    const shoulderInner = 0.092 * profile.shoulder;
    const neckline = front ? 1.345 : 1.385;
    const shape = new THREE.Shape();

    shape.moveTo(-side, 1.245);
    shape.lineTo(-0.174 * profile.shoulder, 1.315);
    shape.lineTo(-shoulderOuter, 1.375);
    shape.lineTo(-0.154 * profile.shoulder, 1.424);
    shape.lineTo(-shoulderInner, 1.431);
    shape.lineTo(-0.060, 1.392);
    shape.lineTo(0, neckline);
    shape.lineTo(0.060, 1.392);
    shape.lineTo(shoulderInner, 1.431);
    shape.lineTo(0.154 * profile.shoulder, 1.424);
    shape.lineTo(shoulderOuter, 1.375);
    shape.lineTo(0.174 * profile.shoulder, 1.315);
    shape.lineTo(side, 1.245);
    shape.closePath();

    const geometry = new THREE.ExtrudeGeometry(shape, {
        depth: 0.008,
        bevelEnabled: false,
        curveSegments: 3,
    });
    geometry.computeVertexNormals();
    return geometry;
}

function mesh(THREE, geometry, material, name) {
    const result = new THREE.Mesh(geometry, material);
    result.name = name;
    result.castShadow = true;
    result.receiveShadow = true;
    return result;
}

function ellipsoid(THREE, radius, scale, material, position, name, widthSegments = 32, heightSegments = 22) {
    const result = mesh(THREE, new THREE.SphereGeometry(radius, widthSegments, heightSegments), material, name);
    result.scale.set(scale[0], scale[1], scale[2]);
    result.position.set(position[0], position[1], position[2]);
    return result;
}

function torsoRings(profile) {
    const { chest, shoulder, waist, hips, depth } = profile;

    return [
        { y: 0.84, rx: 0.158 * hips, rz: 0.090 * depth },
        { y: 0.91, rx: 0.174 * hips, rz: 0.101 * depth },
        { y: 0.99, rx: 0.145 * waist, rz: 0.082 * depth },
        { y: 1.08, rx: 0.154 * waist, rz: 0.088 * depth },
        { y: 1.17, rx: 0.186 * chest, rz: 0.100 * depth },
        { y: 1.27, rx: 0.205 * chest, rz: 0.111 * depth },
        { y: 1.34, rx: 0.214 * shoulder, rz: 0.108 * depth },
        { y: 1.395, rx: 0.202 * shoulder, rz: 0.098 * depth },
        { y: 1.435, rx: 0.132 * shoulder, rz: 0.077 * depth },
    ];
}

function buildBody(THREE, group, profile, skinMaterial) {
    group.add(mesh(
        THREE,
        createLoftYGeometry(THREE, torsoRings(profile), 44),
        skinMaterial,
        'MSKBA_Male_Torso',
    ));

    group.add(mesh(THREE, createLoftYGeometry(THREE, [
        { y: 1.405, rx: 0.067 * profile.shoulder, rz: 0.056 * profile.depth },
        { y: 1.455, rx: 0.059 * profile.shoulder, rz: 0.051 * profile.depth },
        { y: 1.515, rx: 0.052, rz: 0.047 },
        { y: 1.55, rx: 0.051, rz: 0.047 },
    ], 32), skinMaterial, 'MSKBA_Male_Neck'));

    group.add(mesh(THREE, createLoftYGeometry(THREE, [
        { y: 1.50, rx: 0.044, rz: 0.048, cz: 0.008 },
        { y: 1.55, rx: 0.062, rz: 0.061, cz: 0.010 },
        { y: 1.61, rx: 0.076, rz: 0.075, cz: 0.010 },
        { y: 1.68, rx: 0.081, rz: 0.079, cz: 0.005 },
        { y: 1.74, rx: 0.073, rz: 0.073, cz: 0 },
        { y: BODY_CROWN_Y, rx: 0.045, rz: 0.054, cz: -0.004 },
    ], 44), skinMaterial, 'MSKBA_Male_Head'));

    for (const sign of [-1, 1]) {
        const armGeometry = createPathLoftGeometry(THREE, [
            { x: sign * 0.105 * profile.shoulder, y: 1.414, z: -0.002, ra: 0.052 * profile.shoulder, rb: 0.049 * profile.depth },
            { x: sign * 0.155 * profile.shoulder, y: 1.407, z: 0, ra: 0.063 * profile.shoulder, rb: 0.057 * profile.depth },
            { x: sign * 0.205 * profile.shoulder, y: 1.377, z: 0.002, ra: 0.079 * profile.shoulder, rb: 0.071 * profile.depth },
            { x: sign * 0.238 * profile.shoulder, y: 1.315, z: 0.005, ra: 0.068 * profile.arms, rb: 0.061 * profile.arms },
            { x: sign * 0.251 * profile.shoulder, y: 1.235, z: 0.009, ra: 0.058 * profile.arms, rb: 0.052 * profile.arms },
            { x: sign * 0.255 * profile.shoulder, y: 1.14, z: 0.012, ra: 0.050 * profile.arms, rb: 0.046 * profile.arms },
            { x: sign * 0.252 * profile.shoulder, y: 1.06, z: 0.015, ra: 0.041 * profile.arms, rb: 0.039 * profile.arms },
            { x: sign * 0.246 * profile.shoulder, y: 0.985, z: 0.019, ra: 0.044 * profile.arms, rb: 0.041 * profile.arms },
            { x: sign * 0.238 * profile.shoulder, y: 0.88, z: 0.025, ra: 0.036 * profile.arms, rb: 0.033 * profile.arms },
            { x: sign * 0.231 * profile.shoulder, y: 0.80, z: 0.031, ra: 0.028 * profile.arms, rb: 0.026 * profile.arms },
            { x: sign * 0.229 * profile.shoulder, y: 0.744, z: 0.037, ra: 0.034 * profile.arms, rb: 0.021 * profile.arms },
            { x: sign * 0.229 * profile.shoulder, y: 0.695, z: 0.043, ra: 0.024 * profile.arms, rb: 0.015 * profile.arms },
        ], 30);
        group.add(mesh(THREE, armGeometry, skinMaterial, sign < 0 ? 'MSKBA_Male_LeftArm' : 'MSKBA_Male_RightArm'));
    }

    for (const sign of [-1, 1]) {
        const legX = sign * 0.090 * profile.hips;
        const legGeometry = createPathLoftGeometry(THREE, [
            { x: legX, y: 0.89, z: -0.002, ra: 0.086 * profile.legs, rb: 0.081 * profile.depth },
            { x: sign * 0.096 * profile.hips, y: 0.80, z: 0.004, ra: 0.080 * profile.legs, rb: 0.075 * profile.legs },
            { x: sign * 0.101 * profile.hips, y: 0.68, z: 0.008, ra: 0.069 * profile.legs, rb: 0.065 * profile.legs },
            { x: sign * 0.104 * profile.hips, y: 0.58, z: 0.012, ra: 0.056 * profile.legs, rb: 0.053 * profile.legs },
            { x: sign * 0.105 * profile.hips, y: 0.50, z: 0.010, ra: 0.050 * profile.legs, rb: 0.048 * profile.legs },
            { x: sign * 0.105 * profile.hips, y: 0.40, z: 0, ra: 0.059 * profile.legs, rb: 0.054 * profile.legs },
            { x: sign * 0.104 * profile.hips, y: 0.29, z: -0.005, ra: 0.049 * profile.legs, rb: 0.046 * profile.legs },
            { x: sign * 0.103 * profile.hips, y: 0.18, z: 0, ra: 0.035 * profile.legs, rb: 0.033 * profile.legs },
            { x: sign * 0.103 * profile.hips, y: 0.105, z: 0.020, ra: 0.030 * profile.legs, rb: 0.030 * profile.legs },
        ], 30);
        group.add(mesh(THREE, legGeometry, skinMaterial, sign < 0 ? 'MSKBA_Male_LeftLeg' : 'MSKBA_Male_RightLeg'));
    }

    for (const sign of [-1, 1]) {
        group.add(ellipsoid(
            THREE,
            0.025,
            [0.65, 1.0, 0.48],
            skinMaterial,
            [sign * 0.081, 1.66, 0],
            'MSKBA_Male_Ear',
        ));
    }
}

function buildFace(THREE, group, skinMaterial) {
    const detailMaterial = createMaterial(THREE, '#35251f', { roughness: 0.86, clearcoat: 0 });

    for (const x of [-0.028, 0.028]) {
        group.add(ellipsoid(THREE, 0.012, [1.0, 0.52, 0.35], detailMaterial, [x, 1.665, 0.075], 'MSKBA_Male_Eye'));
    }

    group.add(ellipsoid(THREE, 0.024, [0.62, 1.15, 0.72], skinMaterial, [0, 1.625, 0.083], 'MSKBA_Male_Nose'));

    const mouth = mesh(THREE, new THREE.BoxGeometry(0.052, 0.005, 0.005), detailMaterial, 'MSKBA_Male_Mouth');
    mouth.position.set(0, 1.575, 0.073);
    group.add(mouth);
}

function addHairCap(THREE, group, material, rings, name) {
    const cap = mesh(
        THREE,
        createLoftYGeometry(THREE, rings, 40, false, true),
        material,
        name,
    );
    group.add(cap);
    return cap;
}

function buildHair(THREE, group, state) {
    const hairstyle = state.hairstyle || 'male_fade';
    if (hairstyle === 'male_bald') {
        return;
    }

    const hairMaterial = createMaterial(
        THREE,
        HAIR_TONES[state.hairColor] || HAIR_TONES.dark_brown,
        { roughness: 0.92, clearcoat: 0, sheen: 0.04 },
    );
    hairMaterial.userData.playerCharacterRole = 'hair';

    if (hairstyle === 'male_buzz') {
        addHairCap(THREE, group, hairMaterial, [
            { y: 1.685, rx: 0.0835, rz: 0.0815, cz: 0.002 },
            { y: 1.735, rx: 0.0780, rz: 0.0770, cz: -0.001 },
            { y: 1.783, rx: 0.0560, rz: 0.0630, cz: -0.004 },
            { y: 1.802, rx: 0.0280, rz: 0.0350, cz: -0.005 },
        ], 'MSKBA_Hair_Buzz');
        return;
    }

    if (hairstyle === 'male_fade') {
        addHairCap(THREE, group, hairMaterial, [
            { y: 1.655, rx: 0.0825, rz: 0.0805, cz: 0.001 },
            { y: 1.705, rx: 0.0830, rz: 0.0810, cz: 0 },
            { y: 1.755, rx: 0.0740, rz: 0.0760, cz: -0.002 },
            { y: 1.795, rx: 0.0570, rz: 0.0640, cz: -0.006 },
            { y: 1.818, rx: 0.0260, rz: 0.0350, cz: -0.009 },
        ], 'MSKBA_Hair_Fade');
        return;
    }

    if (hairstyle === 'male_short') {
        addHairCap(THREE, group, hairMaterial, [
            { y: 1.675, rx: 0.0840, rz: 0.0820, cz: 0.001 },
            { y: 1.725, rx: 0.0840, rz: 0.0820, cz: -0.002 },
            { y: 1.775, rx: 0.0770, rz: 0.0800, cx: 0.004, cz: -0.005 },
            { y: 1.815, rx: 0.0610, rz: 0.0700, cx: 0.008, cz: -0.010 },
            { y: 1.838, rx: 0.0300, rz: 0.0440, cx: 0.010, cz: -0.013 },
        ], 'MSKBA_Hair_Short');
        return;
    }

    addHairCap(THREE, group, hairMaterial, [
        { y: 1.665, rx: 0.0830, rz: 0.0810, cz: 0 },
        { y: 1.720, rx: 0.0840, rz: 0.0830, cz: -0.002 },
        { y: 1.775, rx: 0.0740, rz: 0.0790, cz: -0.005 },
        { y: 1.807, rx: 0.0480, rz: 0.0580, cz: -0.008 },
    ], 'MSKBA_Hair_Curls_Cap');

    const curls = [
        [-0.052, 1.784, 0.036, 0.029],
        [-0.018, 1.812, 0.050, 0.031],
        [0.020, 1.817, 0.047, 0.031],
        [0.053, 1.790, 0.033, 0.029],
        [-0.061, 1.772, -0.004, 0.028],
        [-0.032, 1.822, 0.005, 0.032],
        [0.004, 1.842, 0.010, 0.033],
        [0.040, 1.827, 0.003, 0.032],
        [0.064, 1.778, -0.010, 0.028],
        [-0.047, 1.795, -0.041, 0.029],
        [-0.012, 1.832, -0.047, 0.031],
        [0.026, 1.830, -0.045, 0.031],
        [0.052, 1.797, -0.038, 0.029],
    ];

    curls.forEach(([x, y, z, radius], index) => {
        group.add(ellipsoid(
            THREE,
            radius,
            [1.02, 0.92 + (index % 3) * 0.05, 1.0],
            hairMaterial,
            [x, y, z],
            'MSKBA_Hair_Curl',
            20,
            14,
        ));
    });
}

function buildUniform(THREE, group, profile, state) {
    const kit = UNIFORM_TONES[state.uniformKit] || UNIFORM_TONES.mskba_home;
    const jerseyMaterial = createMaterial(THREE, kit.primary, { roughness: 0.80, sheen: 0.20 });
    const shortsMaterial = createMaterial(THREE, kit.secondary, { roughness: 0.82, sheen: 0.16 });
    const accentMaterial = createMaterial(THREE, kit.accent, { roughness: 0.74, sheen: 0.13 });
    const shoeMaterial = createMaterial(THREE, '#111412', { roughness: 0.58 });

    jerseyMaterial.userData.playerCharacterRole = 'uniform';
    shortsMaterial.userData.playerCharacterRole = 'uniform-secondary';
    accentMaterial.userData.playerCharacterRole = 'uniform-accent';
    shoeMaterial.userData.playerCharacterRole = 'shoe';

    const jersey = mesh(THREE, createLoftYGeometry(THREE, [
        { y: 0.865, rx: 0.183 * profile.hips + 0.025, rz: 0.103 * profile.depth + 0.022 },
        { y: 0.950, rx: 0.180 * profile.hips + 0.026, rz: 0.101 * profile.depth + 0.022 },
        { y: 1.060, rx: 0.174 * profile.waist + 0.030, rz: 0.096 * profile.depth + 0.023 },
        { y: 1.165, rx: 0.195 * profile.chest + 0.030, rz: 0.108 * profile.depth + 0.023 },
        { y: 1.245, rx: 0.210 * profile.chest + 0.025, rz: 0.116 * profile.depth + 0.022 },
        { y: 1.285, rx: 0.205 * profile.chest + 0.021, rz: 0.114 * profile.depth + 0.020 },
    ], 44, false, false), jerseyMaterial, 'Procedural_Jersey_Body');
    group.add(jersey);

    const frontDepth = 0.116 * profile.depth + 0.022;
    const backDepth = 0.105 * profile.depth + 0.018;

    const frontPanel = mesh(THREE, createJerseyPanelGeometry(THREE, profile, true), jerseyMaterial, 'Procedural_Jersey_Front');
    frontPanel.position.z = frontDepth;
    group.add(frontPanel);

    const backPanel = mesh(THREE, createJerseyPanelGeometry(THREE, profile, false), jerseyMaterial, 'Procedural_Jersey_Back');
    backPanel.position.z = -backDepth - 0.008;
    group.add(backPanel);

    for (const sign of [-1, 1]) {
        group.add(mesh(THREE, createPathLoftGeometry(THREE, [
            { x: sign * 0.130 * profile.shoulder, y: 1.414, z: frontDepth + 0.004, ra: 0.012, rb: 0.040 * profile.shoulder },
            { x: sign * 0.132 * profile.shoulder, y: 1.423, z: 0.020, ra: 0.013, rb: 0.041 * profile.shoulder },
            { x: sign * 0.130 * profile.shoulder, y: 1.414, z: -backDepth - 0.004, ra: 0.012, rb: 0.040 * profile.shoulder },
        ], 20), jerseyMaterial, 'Procedural_Jersey_ShoulderBridge'));
    }

    for (const sign of [-1, 1]) {
        const sidePanel = mesh(
            THREE,
            new THREE.BoxGeometry(0.012, 0.355, 0.014),
            accentMaterial,
            'Procedural_Jersey_SidePanel',
        );
        sidePanel.position.set(sign * (0.198 * profile.chest + 0.021), 1.055, 0.090 * profile.depth);
        group.add(sidePanel);
    }

    group.add(mesh(THREE, createLoftYGeometry(THREE, [
        { y: 0.720, rx: 0.186 * profile.hips, rz: 0.106 * profile.depth },
        { y: 0.790, rx: 0.192 * profile.hips, rz: 0.111 * profile.depth },
        { y: 0.865, rx: 0.190 * profile.hips, rz: 0.109 * profile.depth },
        { y: 0.940, rx: 0.181 * profile.hips, rz: 0.103 * profile.depth },
    ], 44, false, false), shortsMaterial, 'Procedural_Shorts_Waist'));

    for (const sign of [-1, 1]) {
        const cx = sign * 0.088 * profile.hips;
        group.add(mesh(THREE, createLoftYGeometry(THREE, [
            { y: 0.805, cx, rx: 0.105 * profile.legs, rz: 0.095 * profile.depth },
            { y: 0.735, cx: sign * 0.091 * profile.hips, rx: 0.104 * profile.legs, rz: 0.092 * profile.depth },
            { y: 0.655, cx: sign * 0.095 * profile.hips, rx: 0.098 * profile.legs, rz: 0.086 * profile.depth },
            { y: 0.575, cx: sign * 0.098 * profile.hips, rx: 0.091 * profile.legs, rz: 0.078 * profile.depth },
        ], 38, false, false), shortsMaterial, sign < 0 ? 'Procedural_Shorts_LeftLeg' : 'Procedural_Shorts_RightLeg'));
    }

    for (const sign of [-1, 1]) {
        const x = sign * 0.103 * profile.hips;
        const sock = mesh(
            THREE,
            new THREE.CylinderGeometry(0.037 * profile.legs, 0.032 * profile.legs, 0.18, 24),
            shortsMaterial,
            'Procedural_Sock',
        );
        sock.scale.z = 0.92;
        sock.position.set(x, 0.195, 0.004);
        group.add(sock);

        group.add(ellipsoid(
            THREE,
            0.10,
            [0.72, 0.38, 1.38],
            shoeMaterial,
            [x, 0.055, 0.105],
            'Procedural_Shoe',
        ));

        const soleVerticalRadius = 0.102 * 0.13;
        group.add(ellipsoid(
            THREE,
            0.102,
            [0.74, 0.13, 1.42],
            accentMaterial,
            [x, soleVerticalRadius, 0.110],
            'Procedural_Shoe_Sole',
        ));
    }
}

function disposeGroup(group) {
    group.traverse((object) => {
        object.geometry?.dispose?.();
        const materials = Array.isArray(object.material) ? object.material : [object.material];
        materials.filter(Boolean).forEach((material) => material.dispose?.());
    });
    group.clear();
}

function rebuildMalePlayer(THREE, group, state) {
    disposeGroup(group);

    const profile = profileForState(state);
    const skinMaterial = createMaterial(
        THREE,
        SKIN_TONES[state.skinTone] || SKIN_TONES.warm,
        { roughness: 0.76, clearcoat: 0.018, sheen: 0.04 },
    );
    skinMaterial.userData.playerCharacterRole = 'skin';

    buildBody(THREE, group, profile, skinMaterial);
    buildFace(THREE, group, skinMaterial);
    buildHair(THREE, group, state);
    buildUniform(THREE, group, profile, state);

    group.userData.proceduralProfile = profile;
    group.userData.playerCharacterBase = 'procedural-male-loft-v2';
    group.userData.playerCharacterMetric = {
        floorY: FLOOR_Y,
        crownY: BODY_CROWN_Y,
    };
}

export function createProceduralMalePlayer(engine, state) {
    const group = new engine.THREE.Group();
    group.name = 'MSKBA_ProceduralMale_v3';
    rebuildMalePlayer(engine.THREE, group, state);
    return group;
}

export function updateProceduralMalePlayer(engine, model, state) {
    if (!model?.isGroup || !model.userData?.playerCharacterBase?.startsWith('procedural-male-')) {
        return false;
    }

    rebuildMalePlayer(engine.THREE, model, state);
    return true;
}
