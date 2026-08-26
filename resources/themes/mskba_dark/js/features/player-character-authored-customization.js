const CANONICAL_HEIGHT_METERS = 1.79;

const BODY_TYPE_SHAPE = {
    unspecified: { width: 0, depth: 0 },
    slim: { width: -0.07, depth: -0.05 },
    athletic: { width: 0, depth: 0 },
    muscular: { width: 0.07, depth: 0.06 },
    stocky: { width: 0.10, depth: 0.11 },
    large: { width: 0.14, depth: 0.17 },
};

const HAIR_TONES = {
    black: '#171513',
    dark_brown: '#3a271f',
    brown: '#694733',
    blond: '#c9aa70',
    ginger: '#9a4c2c',
    gray: '#8c8b87',
};

function clamp(value, min, max) {
    return Math.min(max, Math.max(min, value));
}

function bodyShapeForState(state) {
    const heightCm = Number(state.heightCm) || 180;
    const weightKg = Number(state.weightKg) || 80;
    const heightMeters = Math.max(1.45, heightCm / 100);
    const bmi = weightKg / (heightMeters * heightMeters);
    const mass = clamp((bmi - 22.5) / 13, -0.65, 1);
    const type = BODY_TYPE_SHAPE[state.bodyType] || BODY_TYPE_SHAPE.unspecified;

    return {
        width: clamp(1 + type.width + mass * 0.075, 0.84, 1.25),
        depth: clamp(1 + type.depth + mass * 0.11, 0.82, 1.34),
    };
}

function material(THREE, color, options = {}) {
    return new THREE.MeshStandardMaterial({
        color,
        roughness: options.roughness ?? 0.88,
        metalness: 0,
        transparent: options.transparent ?? false,
        opacity: options.opacity ?? 1,
        depthWrite: options.depthWrite ?? true,
    });
}

function mesh(THREE, geometry, meshMaterial, name) {
    const result = new THREE.Mesh(geometry, meshMaterial);
    result.name = name;
    result.castShadow = true;
    result.receiveShadow = true;
    return result;
}

function ellipsoid(THREE, radius, scale, meshMaterial, position, name, widthSegments = 24, heightSegments = 16) {
    const result = mesh(
        THREE,
        new THREE.SphereGeometry(radius, widthSegments, heightSegments),
        meshMaterial,
        name,
    );
    result.scale.set(scale[0], scale[1], scale[2]);
    result.position.set(position[0], position[1], position[2]);
    return result;
}

function createLoftYGeometry(THREE, rings, radialSegments = 36, capBottom = false, capTop = true) {
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
    return geometry;
}

function addHairCap(THREE, group, hairMaterial, rings, name) {
    group.add(mesh(
        THREE,
        createLoftYGeometry(THREE, rings),
        hairMaterial,
        name,
    ));
}

function addHairTuft(THREE, group, hairMaterial, position, scale, name) {
    group.add(ellipsoid(
        THREE,
        0.050,
        scale,
        hairMaterial,
        position,
        name,
        24,
        16,
    ));
}

function buildHair(THREE, group, state, hairMaterial) {
    const hairstyle = state.hairstyle || 'male_fade';
    if (hairstyle === 'male_bald') {
        return;
    }

    if (hairstyle === 'male_buzz') {
        addHairCap(THREE, group, hairMaterial, [
            { y: 1.676, rx: 0.0825, rz: 0.0805, cz: 0.002 },
            { y: 1.724, rx: 0.0790, rz: 0.0780 },
            { y: 1.764, rx: 0.0680, rz: 0.0710, cz: -0.002 },
            { y: 1.792, rx: 0.0460, rz: 0.0550, cz: -0.004 },
            { y: 1.800, rx: 0.0240, rz: 0.0310, cz: -0.005 },
        ], 'MSKBA_Authored_Hair_Buzz');
        return;
    }

    if (hairstyle === 'male_fade') {
        addHairCap(THREE, group, hairMaterial, [
            { y: 1.666, rx: 0.0820, rz: 0.0800, cz: 0.001 },
            { y: 1.710, rx: 0.0800, rz: 0.0790 },
            { y: 1.750, rx: 0.0710, rz: 0.0740, cz: -0.002 },
            { y: 1.786, rx: 0.0530, rz: 0.0610, cz: -0.005 },
            { y: 1.806, rx: 0.0280, rz: 0.0360, cz: -0.007 },
        ], 'MSKBA_Authored_Hair_Fade_Sides');
        addHairTuft(
            THREE,
            group,
            hairMaterial,
            [0, 1.792, 0.011],
            [1.38, 0.72, 1.22],
            'MSKBA_Authored_Hair_Fade_Top',
        );
        return;
    }

    if (hairstyle === 'male_short') {
        addHairCap(THREE, group, hairMaterial, [
            { y: 1.668, rx: 0.0835, rz: 0.0815, cz: 0.001 },
            { y: 1.716, rx: 0.0820, rz: 0.0810, cz: -0.001 },
            { y: 1.760, rx: 0.0720, rz: 0.0760, cz: -0.004 },
            { y: 1.800, rx: 0.0520, rz: 0.0610, cx: 0.004, cz: -0.008 },
            { y: 1.823, rx: 0.0270, rz: 0.0360, cx: 0.006, cz: -0.011 },
        ], 'MSKBA_Authored_Hair_Short_Cap');
        addHairTuft(THREE, group, hairMaterial, [-0.034, 1.815, 0.021], [0.86, 0.66, 0.90], 'MSKBA_Authored_Hair_Short_Tuft');
        addHairTuft(THREE, group, hairMaterial, [0.004, 1.830, 0.028], [0.92, 0.72, 0.94], 'MSKBA_Authored_Hair_Short_Tuft');
        addHairTuft(THREE, group, hairMaterial, [0.040, 1.812, 0.018], [0.78, 0.62, 0.86], 'MSKBA_Authored_Hair_Short_Tuft');
        return;
    }

    addHairCap(THREE, group, hairMaterial, [
        { y: 1.660, rx: 0.0830, rz: 0.0810 },
        { y: 1.710, rx: 0.0840, rz: 0.0830, cz: -0.001 },
        { y: 1.755, rx: 0.0760, rz: 0.0790, cz: -0.004 },
        { y: 1.790, rx: 0.0550, rz: 0.0630, cz: -0.006 },
        { y: 1.805, rx: 0.0320, rz: 0.0420, cz: -0.008 },
    ], 'MSKBA_Authored_Hair_Curls_Cap');

    const curls = [
        [-0.066, 1.740, 0.025, 0.029],
        [-0.068, 1.754, -0.012, 0.029],
        [0.067, 1.742, 0.024, 0.029],
        [0.069, 1.755, -0.014, 0.029],
        [-0.052, 1.775, 0.050, 0.030],
        [-0.017, 1.796, 0.061, 0.031],
        [0.020, 1.801, 0.058, 0.031],
        [0.053, 1.778, 0.048, 0.030],
        [-0.050, 1.805, 0.018, 0.031],
        [-0.016, 1.828, 0.024, 0.032],
        [0.021, 1.833, 0.021, 0.032],
        [0.052, 1.808, 0.014, 0.031],
        [-0.055, 1.790, -0.038, 0.030],
        [-0.020, 1.819, -0.050, 0.032],
        [0.018, 1.824, -0.051, 0.032],
        [0.053, 1.794, -0.040, 0.030],
        [0.000, 1.850, -0.008, 0.033],
    ];

    curls.forEach(([x, y, z, radius], index) => {
        group.add(ellipsoid(
            THREE,
            radius,
            [1.04, 0.94 + (index % 3) * 0.05, 1.02],
            hairMaterial,
            [x, y, z],
            'MSKBA_Authored_Hair_Curl',
            20,
            14,
        ));
    });
}

function facialMaterial(baseMaterial, opacity = 1) {
    const copy = baseMaterial.clone();
    copy.opacity = opacity;
    copy.transparent = opacity < 1;
    copy.depthWrite = opacity >= 1;
    return copy;
}

function addMustache(THREE, group, hairMaterial, opacity = 1) {
    const left = ellipsoid(
        THREE,
        0.024,
        [1.05, 0.34, 0.42],
        facialMaterial(hairMaterial, opacity),
        [-0.019, 1.583, 0.083],
        'MSKBA_Authored_Mustache',
    );
    const right = ellipsoid(
        THREE,
        0.024,
        [1.05, 0.34, 0.42],
        facialMaterial(hairMaterial, opacity),
        [0.019, 1.583, 0.083],
        'MSKBA_Authored_Mustache',
    );
    left.rotation.z = 0.12;
    right.rotation.z = -0.12;
    group.add(left, right);
}

function addJawPatches(THREE, group, hairMaterial, scale = 1, opacity = 1, centralOnly = false) {
    const patches = centralOnly
        ? [
            [0, 1.535, 0.075, 0.039, [0.92, 0.88, 0.54]],
            [0, 1.512, 0.071, 0.033, [0.78, 0.90, 0.56]],
        ]
        : [
            [-0.050, 1.575, 0.065, 0.034, [0.78, 0.72, 0.48]],
            [0.050, 1.575, 0.065, 0.034, [0.78, 0.72, 0.48]],
            [-0.035, 1.548, 0.071, 0.035, [0.90, 0.78, 0.52]],
            [0.035, 1.548, 0.071, 0.035, [0.90, 0.78, 0.52]],
            [0, 1.528, 0.073, 0.040, [1.08, 0.90, 0.58]],
        ];

    patches.forEach(([x, y, z, radius, patchScale]) => {
        group.add(ellipsoid(
            THREE,
            radius * scale,
            patchScale,
            facialMaterial(hairMaterial, opacity),
            [x, y, z],
            'MSKBA_Authored_Beard',
            20,
            14,
        ));
    });
}

function buildFacialHair(THREE, group, state, hairMaterial) {
    const facialHair = state.facialHair || 'none';
    if (facialHair === 'none') {
        return;
    }

    if (facialHair === 'stubble') {
        addMustache(THREE, group, hairMaterial, 0.32);
        addJawPatches(THREE, group, hairMaterial, 0.86, 0.28);
        return;
    }

    if (facialHair === 'mustache') {
        addMustache(THREE, group, hairMaterial);
        return;
    }

    if (facialHair === 'goatee') {
        addMustache(THREE, group, hairMaterial);
        addJawPatches(THREE, group, hairMaterial, 0.88, 1, true);
        return;
    }

    if (facialHair === 'short_beard') {
        addMustache(THREE, group, hairMaterial);
        addJawPatches(THREE, group, hairMaterial, 0.92, 1);
        return;
    }

    addMustache(THREE, group, hairMaterial);
    addJawPatches(THREE, group, hairMaterial, 1.14, 1);
    group.add(ellipsoid(
        THREE,
        0.046,
        [1.02, 1.18, 0.62],
        facialMaterial(hairMaterial),
        [0, 1.502, 0.071],
        'MSKBA_Authored_Full_Beard_Chin',
        22,
        16,
    ));
}

function disposeGroup(group) {
    group.traverse((object) => {
        if (object === group) {
            return;
        }

        object.geometry?.dispose?.();
        const materials = Array.isArray(object.material) ? object.material : [object.material];
        materials.filter(Boolean).forEach((entry) => entry.dispose?.());
    });
    group.clear();
}

function ensureAccessoryRoot(runtime) {
    if (runtime.accessoryRoot) {
        return runtime.accessoryRoot;
    }

    const root = new runtime.THREE.Group();
    root.name = 'MSKBA_Authored_Accessories';
    runtime.modelRoot.add(root);
    runtime.accessoryRoot = root;
    return root;
}

function syncAccessoryScale(runtime) {
    if (!runtime.accessoryRoot || !runtime.modelBaseHeight) {
        return;
    }

    const canonicalScale = runtime.modelBaseHeight / CANONICAL_HEIGHT_METERS;
    const widthScale = runtime.bodyWidthScale || 1;
    const depthScale = runtime.bodyDepthScale || 1;
    runtime.accessoryRoot.scale.set(
        canonicalScale * widthScale,
        canonicalScale,
        canonicalScale * depthScale,
    );
}

export function applyAuthoredBodyShape(runtime, state) {
    if (!runtime.model) {
        return;
    }

    const shape = bodyShapeForState(state);
    runtime.bodyWidthScale = shape.width;
    runtime.bodyDepthScale = shape.depth;
    runtime.model.scale.set(shape.width, 1, shape.depth);

    if (runtime.shadow) {
        runtime.shadow.scale.x = shape.width;
        runtime.shadow.scale.y = 0.34 * shape.depth;
    }

    syncAccessoryScale(runtime);
}

export function updateAuthoredAccessories(runtime, state) {
    if (!runtime.modelBaseHeight) {
        return;
    }

    const root = ensureAccessoryRoot(runtime);
    disposeGroup(root);

    const hairstyle = state.hairstyle || 'male_fade';
    const facialHair = state.facialHair || 'none';
    if (hairstyle === 'male_bald' && facialHair === 'none') {
        syncAccessoryScale(runtime);
        return;
    }

    const color = HAIR_TONES[state.hairColor] || HAIR_TONES.dark_brown;
    const hairMaterial = material(runtime.THREE, color, { roughness: 0.92 });
    hairMaterial.userData.playerCharacterRole = 'hair';

    buildHair(runtime.THREE, root, state, hairMaterial);
    buildFacialHair(runtime.THREE, root, state, hairMaterial);
    syncAccessoryScale(runtime);
}

export function destroyAuthoredAccessories(runtime) {
    if (!runtime.accessoryRoot) {
        return;
    }

    disposeGroup(runtime.accessoryRoot);
    runtime.modelRoot?.remove(runtime.accessoryRoot);
    runtime.accessoryRoot = null;
}
