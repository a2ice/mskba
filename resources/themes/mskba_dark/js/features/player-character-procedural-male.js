const DEFAULT_HEIGHT_CM = 180;
const DEFAULT_WEIGHT_KG = 80;

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

function mesh(THREE, geometry, material, name) {
    const result = new THREE.Mesh(geometry, material);
    result.name = name;
    result.castShadow = true;
    result.receiveShadow = true;
    return result;
}

function ellipsoid(THREE, radius, scale, material, position, name) {
    const result = mesh(THREE, new THREE.SphereGeometry(radius, 32, 22), material, name);
    result.scale.set(scale[0], scale[1], scale[2]);
    result.position.set(position[0], position[1], position[2]);
    return result;
}

function torsoRings(profile, inset = 0) {
    const chest = profile.chest;
    const shoulder = profile.shoulder;
    const waist = profile.waist;
    const hips = profile.hips;
    const depth = profile.depth;

    return [
        { y: 0.84, rx: 0.158 * hips + inset, rz: 0.090 * depth + inset * 0.55 },
        { y: 0.91, rx: 0.174 * hips + inset, rz: 0.101 * depth + inset * 0.55 },
        { y: 0.99, rx: 0.145 * waist + inset, rz: 0.082 * depth + inset * 0.55 },
        { y: 1.08, rx: 0.154 * waist + inset, rz: 0.088 * depth + inset * 0.55 },
        { y: 1.17, rx: 0.186 * chest + inset, rz: 0.100 * depth + inset * 0.55 },
        { y: 1.27, rx: 0.205 * chest + inset, rz: 0.111 * depth + inset * 0.55 },
        { y: 1.35, rx: 0.195 * shoulder + inset, rz: 0.104 * depth + inset * 0.55 },
        { y: 1.42, rx: 0.120 * shoulder + inset, rz: 0.073 * depth + inset * 0.55 },
    ];
}

function buildBody(THREE, group, profile, skinMaterial) {
    const torso = mesh(
        THREE,
        createLoftYGeometry(THREE, torsoRings(profile), 40),
        skinMaterial,
        'MSKBA_Male_Torso',
    );
    group.add(torso);

    const neck = mesh(THREE, createLoftYGeometry(THREE, [
        { y: 1.39, rx: 0.061 * profile.shoulder, rz: 0.052 * profile.depth },
        { y: 1.47, rx: 0.055 * profile.shoulder, rz: 0.048 * profile.depth },
        { y: 1.54, rx: 0.051, rz: 0.047 },
    ], 30), skinMaterial, 'MSKBA_Male_Neck');
    group.add(neck);

    const head = mesh(THREE, createLoftYGeometry(THREE, [
        { y: 1.50, rx: 0.044, rz: 0.048, cz: 0.008 },
        { y: 1.55, rx: 0.062, rz: 0.061, cz: 0.010 },
        { y: 1.61, rx: 0.076, rz: 0.075, cz: 0.010 },
        { y: 1.68, rx: 0.081, rz: 0.079, cz: 0.005 },
        { y: 1.74, rx: 0.073, rz: 0.073, cz: 0 },
        { y: 1.79, rx: 0.045, rz: 0.054, cz: -0.004 },
    ], 40), skinMaterial, 'MSKBA_Male_Head');
    group.add(head);

    for (const sign of [-1, 1]) {
        const shoulderX = sign * 0.190 * profile.shoulder;
        const armGeometry = createPathLoftGeometry(THREE, [
            { x: shoulderX, y: 1.38, z: 0, ra: 0.073 * profile.shoulder, rb: 0.068 * profile.depth },
            { x: sign * 0.220 * profile.shoulder, y: 1.30, z: 0.004, ra: 0.060 * profile.arms, rb: 0.054 * profile.arms },
            { x: sign * 0.238 * profile.shoulder, y: 1.19, z: 0.010, ra: 0.052 * profile.arms, rb: 0.048 * profile.arms },
            { x: sign * 0.244 * profile.shoulder, y: 1.08, z: 0.014, ra: 0.041 * profile.arms, rb: 0.039 * profile.arms },
            { x: sign * 0.239 * profile.shoulder, y: 0.99, z: 0.018, ra: 0.045 * profile.arms, rb: 0.041 * profile.arms },
            { x: sign * 0.232 * profile.shoulder, y: 0.88, z: 0.024, ra: 0.036 * profile.arms, rb: 0.033 * profile.arms },
            { x: sign * 0.226 * profile.shoulder, y: 0.80, z: 0.030, ra: 0.028 * profile.arms, rb: 0.026 * profile.arms },
            { x: sign * 0.225 * profile.shoulder, y: 0.745, z: 0.036, ra: 0.034 * profile.arms, rb: 0.021 * profile.arms },
            { x: sign * 0.225 * profile.shoulder, y: 0.695, z: 0.042, ra: 0.024 * profile.arms, rb: 0.015 * profile.arms },
        ], 26);
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
        ], 28);
        group.add(mesh(THREE, legGeometry, skinMaterial, sign < 0 ? 'MSKBA_Male_LeftLeg' : 'MSKBA_Male_RightLeg'));
    }

    for (const sign of [-1, 1]) {
        const ear = ellipsoid(THREE, 0.025, [0.65, 1.0, 0.48], skinMaterial, [sign * 0.081, 1.66, 0], 'MSKBA_Male_Ear');
        group.add(ear);
    }
}

function buildFace(THREE, group, skinMaterial) {
    const detailMaterial = createMaterial(THREE, '#35251f', { roughness: 0.86, clearcoat: 0 });

    for (const x of [-0.028, 0.028]) {
        const eye = ellipsoid(THREE, 0.012, [1.0, 0.52, 0.35], detailMaterial, [x, 1.665, 0.075], 'MSKBA_Male_Eye');
        group.add(eye);
    }

    const nose = ellipsoid(THREE, 0.024, [0.62, 1.15, 0.72], skinMaterial, [0, 1.625, 0.083], 'MSKBA_Male_Nose');
    group.add(nose);

    const mouth = mesh(THREE, new THREE.BoxGeometry(0.052, 0.005, 0.005), detailMaterial, 'MSKBA_Male_Mouth');
    mouth.position.set(0, 1.575, 0.073);
    group.add(mouth);
}

function buildUniform(THREE, group, profile, state) {
    const kit = UNIFORM_TONES[state.uniformKit] || UNIFORM_TONES.mskba_home;
    const jerseyMaterial = createMaterial(THREE, kit.primary, { roughness: 0.80, sheen: 0.20 });
    const secondaryMaterial = createMaterial(THREE, kit.secondary, { roughness: 0.82, sheen: 0.16 });
    const accentMaterial = createMaterial(THREE, kit.accent, { roughness: 0.74, sheen: 0.13 });
    const shoeMaterial = createMaterial(THREE, '#111412', { roughness: 0.58 });

    jerseyMaterial.userData.playerCharacterRole = 'uniform';
    secondaryMaterial.userData.playerCharacterRole = 'uniform-secondary';
    accentMaterial.userData.playerCharacterRole = 'uniform-accent';
    shoeMaterial.userData.playerCharacterRole = 'shoe';

    const jerseyRings = torsoRings(profile, 0.010).filter((ring) => ring.y >= 0.98);
    const jersey = mesh(
        THREE,
        createLoftYGeometry(THREE, jerseyRings, 40, false, false),
        jerseyMaterial,
        'Procedural_Jersey',
    );
    group.add(jersey);

    const collar = mesh(THREE, new THREE.TorusGeometry(0.067, 0.007, 10, 36), accentMaterial, 'Procedural_Jersey_Collar');
    collar.scale.set(1.10 * profile.shoulder, 1, 0.78 * profile.depth);
    collar.rotation.x = Math.PI / 2;
    collar.position.set(0, 1.408, 0.062);
    group.add(collar);

    for (const sign of [-1, 1]) {
        const sidePanel = mesh(THREE, new THREE.BoxGeometry(0.018, 0.34, 0.012), accentMaterial, 'Procedural_Jersey_SidePanel');
        sidePanel.position.set(sign * 0.175 * profile.chest, 1.17, 0.087 * profile.depth);
        group.add(sidePanel);
    }

    const shortsWaist = mesh(THREE, createLoftYGeometry(THREE, [
        { y: 0.72, rx: 0.174 * profile.hips, rz: 0.096 * profile.depth },
        { y: 0.82, rx: 0.182 * profile.hips, rz: 0.104 * profile.depth },
        { y: 0.92, rx: 0.178 * profile.hips, rz: 0.102 * profile.depth },
    ], 36, false, false), secondaryMaterial, 'Procedural_Shorts_Waist');
    group.add(shortsWaist);

    for (const sign of [-1, 1]) {
        const x = sign * 0.087 * profile.hips;
        const shortsLeg = mesh(THREE, createPathLoftGeometry(THREE, [
            { x, y: 0.79, z: 0.006, ra: 0.092 * profile.legs, rb: 0.087 * profile.depth },
            { x: sign * 0.092 * profile.hips, y: 0.70, z: 0.010, ra: 0.087 * profile.legs, rb: 0.080 * profile.depth },
            { x: sign * 0.096 * profile.hips, y: 0.62, z: 0.012, ra: 0.078 * profile.legs, rb: 0.070 * profile.depth },
        ], 28), secondaryMaterial, 'Procedural_Shorts_Leg');
        group.add(shortsLeg);
    }

    for (const sign of [-1, 1]) {
        const x = sign * 0.103 * profile.hips;
        const sock = mesh(THREE, new THREE.CylinderGeometry(0.037 * profile.legs, 0.032 * profile.legs, 0.18, 24), secondaryMaterial, 'Procedural_Sock');
        sock.scale.z = 0.92;
        sock.position.set(x, 0.195, 0.004);
        group.add(sock);

        const shoe = ellipsoid(THREE, 0.10, [0.72, 0.38, 1.38], shoeMaterial, [x, 0.055, 0.105], 'Procedural_Shoe');
        group.add(shoe);

        const sole = ellipsoid(THREE, 0.102, [0.74, 0.13, 1.42], accentMaterial, [x, 0.027, 0.110], 'Procedural_Shoe_Sole');
        group.add(sole);
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
    buildUniform(THREE, group, profile, state);

    group.userData.proceduralProfile = profile;
    group.userData.playerCharacterBase = 'procedural-male-loft-v1';
}

export function createProceduralMalePlayer(engine, state) {
    const group = new engine.THREE.Group();
    group.name = 'MSKBA_ProceduralMale_v2';
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
