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
        roughness: options.roughness ?? 0.9,
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

function quantile(values, amount) {
    if (!values.length) {
        return 0;
    }

    const sorted = [...values].sort((a, b) => a - b);
    const index = clamp(Math.round((sorted.length - 1) * amount), 0, sorted.length - 1);
    return sorted[index];
}

function collectHeadMetrics(runtime) {
    if (runtime.authoredHeadMetrics) {
        return runtime.authoredHeadMetrics;
    }

    const { THREE, model, modelRoot } = runtime;
    if (!model || !modelRoot) {
        return null;
    }

    // Measure the authored mesh in modelRoot-local coordinates. This cancels the
    // current viewport height scale/rotation and makes the accessory anchors follow
    // the actual GLB instead of coordinates borrowed from the procedural prototype.
    model.scale.set(1, 1, 1);
    modelRoot.updateMatrixWorld(true);
    model.updateMatrixWorld(true);

    const inverseRoot = new THREE.Matrix4().copy(modelRoot.matrixWorld).invert();
    const localMatrix = new THREE.Matrix4();
    const point = new THREE.Vector3();
    const samples = [];

    model.traverse((object) => {
        const position = object.geometry?.getAttribute?.('position');
        if (!object.isMesh || !position) {
            return;
        }

        object.updateMatrixWorld(true);
        localMatrix.multiplyMatrices(inverseRoot, object.matrixWorld);

        for (let index = 0; index < position.count; index += 1) {
            point.fromBufferAttribute(position, index).applyMatrix4(localMatrix);
            samples.push({ x: point.x, y: point.y, z: point.z });
        }
    });

    if (!samples.length) {
        return null;
    }

    const ys = samples.map((entry) => entry.y);
    const floorY = Math.min(...ys);
    const crownY = Math.max(...ys);
    const height = Math.max(crownY - floorY, 0.001);
    const headStartY = floorY + height * 0.815;
    const head = samples.filter((entry) => entry.y >= headStartY);

    const xs = head.map((entry) => entry.x);
    const zs = head.map((entry) => entry.z);
    const headMinX = quantile(xs, 0.01);
    const headMaxX = quantile(xs, 0.99);
    const headMinZ = quantile(zs, 0.01);
    const headMaxZ = quantile(zs, 0.99);

    runtime.authoredHeadMetrics = {
        floorY,
        crownY,
        height,
        head,
        centerX: (headMinX + headMaxX) * 0.5,
        centerZ: (headMinZ + headMaxZ) * 0.5,
        halfWidth: Math.max((headMaxX - headMinX) * 0.5, 0.055),
        halfDepth: Math.max((headMaxZ - headMinZ) * 0.5, 0.06),
        hairlineY: floorY + height * 0.895,
        mouthY: floorY + height * 0.852,
        jawY: floorY + height * 0.827,
        chinY: floorY + height * 0.807,
    };

    return runtime.authoredHeadMetrics;
}

function frontSurfaceZ(metrics, x, y) {
    const xTolerance = Math.max(metrics.halfWidth * 0.24, 0.018);
    const yTolerance = Math.max(metrics.height * 0.010, 0.016);
    let candidates = metrics.head.filter((entry) => (
        Math.abs(entry.x - x) <= xTolerance
        && Math.abs(entry.y - y) <= yTolerance
    ));

    if (!candidates.length) {
        candidates = metrics.head.filter((entry) => Math.abs(entry.y - y) <= yTolerance * 1.8);
    }

    if (!candidates.length) {
        return metrics.centerZ + metrics.halfDepth;
    }

    // Camera is on +Z and the authored male faces it at yaw=0, so +Z is the face.
    return quantile(candidates.map((entry) => entry.z), 0.985);
}

function addHairCap(THREE, group, hairMaterial, rings, name) {
    group.add(mesh(THREE, createLoftYGeometry(THREE, rings), hairMaterial, name));
}

function addHairTuft(THREE, group, hairMaterial, position, scale, name) {
    group.add(ellipsoid(THREE, 0.05, scale, hairMaterial, position, name, 24, 16));
}

function buildHair(THREE, group, state, hairMaterial, metrics) {
    const hairstyle = state.hairstyle || 'male_fade';
    if (hairstyle === 'male_bald') {
        return;
    }

    const { centerX: x, centerZ: z, halfWidth: w, halfDepth: d, crownY, hairlineY } = metrics;
    const capTop = crownY + Math.max(metrics.height * 0.003, 0.004);
    const crownSpan = Math.max(crownY - hairlineY, 0.10);

    if (hairstyle === 'male_buzz') {
        addHairCap(THREE, group, hairMaterial, [
            { y: hairlineY, cx: x, cz: z, rx: w * 1.015, rz: d * 1.015 },
            { y: hairlineY + crownSpan * 0.42, cx: x, cz: z, rx: w * 0.95, rz: d * 0.96 },
            { y: hairlineY + crownSpan * 0.78, cx: x, cz: z, rx: w * 0.76, rz: d * 0.82 },
            { y: capTop, cx: x, cz: z - d * 0.04, rx: w * 0.30, rz: d * 0.36 },
        ], 'MSKBA_Authored_Hair_Buzz');
        return;
    }

    if (hairstyle === 'male_fade') {
        addHairCap(THREE, group, hairMaterial, [
            { y: hairlineY + crownSpan * 0.08, cx: x, cz: z, rx: w * 1.01, rz: d * 1.01 },
            { y: hairlineY + crownSpan * 0.48, cx: x, cz: z, rx: w * 0.94, rz: d * 0.96 },
            { y: hairlineY + crownSpan * 0.78, cx: x, cz: z - d * 0.02, rx: w * 0.72, rz: d * 0.79 },
            { y: capTop, cx: x, cz: z - d * 0.05, rx: w * 0.28, rz: d * 0.34 },
        ], 'MSKBA_Authored_Hair_Fade_Sides');
        addHairTuft(
            THREE,
            group,
            hairMaterial,
            [x, crownY + w * 0.13, z + d * 0.03],
            [w / 0.05 * 0.78, w / 0.05 * 0.30, d / 0.05 * 0.70],
            'MSKBA_Authored_Hair_Fade_Top',
        );
        return;
    }

    if (hairstyle === 'male_short') {
        addHairCap(THREE, group, hairMaterial, [
            { y: hairlineY, cx: x, cz: z, rx: w * 1.02, rz: d * 1.02 },
            { y: hairlineY + crownSpan * 0.45, cx: x, cz: z, rx: w * 0.97, rz: d * 0.99 },
            { y: hairlineY + crownSpan * 0.82, cx: x + w * 0.04, cz: z - d * 0.03, rx: w * 0.76, rz: d * 0.83 },
            { y: capTop + w * 0.08, cx: x + w * 0.07, cz: z - d * 0.06, rx: w * 0.30, rz: d * 0.35 },
        ], 'MSKBA_Authored_Hair_Short');
        return;
    }

    addHairCap(THREE, group, hairMaterial, [
        { y: hairlineY, cx: x, cz: z, rx: w * 1.02, rz: d * 1.02 },
        { y: hairlineY + crownSpan * 0.48, cx: x, cz: z, rx: w * 1.00, rz: d * 1.01 },
        { y: hairlineY + crownSpan * 0.82, cx: x, cz: z - d * 0.03, rx: w * 0.78, rz: d * 0.85 },
        { y: capTop, cx: x, cz: z - d * 0.05, rx: w * 0.34, rz: d * 0.40 },
    ], 'MSKBA_Authored_Hair_Curls_Cap');

    const curlRadius = Math.max(w * 0.25, 0.020);
    [
        [-0.62, 0.36, 0.54], [-0.22, 0.52, 0.66], [0.22, 0.54, 0.64], [0.62, 0.36, 0.52],
        [-0.55, 0.72, 0.08], [-0.18, 0.84, 0.18], [0.20, 0.86, 0.16], [0.56, 0.72, 0.06],
        [-0.35, 1.02, -0.18], [0.02, 1.10, -0.20], [0.38, 1.00, -0.18],
    ].forEach(([dx, dy, dz], index) => {
        group.add(ellipsoid(
            THREE,
            curlRadius,
            [1, 0.90 + (index % 3) * 0.08, 0.96],
            hairMaterial,
            [x + dx * w, crownY - crownSpan * 0.30 + dy * w, z + dz * d],
            'MSKBA_Authored_Hair_Curl',
            18,
            12,
        ));
    });
}

function facialMaterial(baseMaterial, opacity = 1) {
    const copy = baseMaterial.clone();
    copy.opacity = opacity;
    copy.transparent = opacity < 1;
    copy.depthWrite = opacity >= 0.95;
    return copy;
}

function addFacialPatch(THREE, group, hairMaterial, metrics, x, y, radius, scale, name, opacity = 1) {
    // Put the patch on the measured face surface, not at a hard-coded procedural Z.
    const z = frontSurfaceZ(metrics, x, y) + 0.0015;
    group.add(ellipsoid(
        THREE,
        radius,
        [scale[0], scale[1], scale[2]],
        facialMaterial(hairMaterial, opacity),
        [x, y, z],
        name,
        20,
        12,
    ));
}

function addMustache(THREE, group, hairMaterial, metrics, opacity = 1) {
    const xOffset = metrics.halfWidth * 0.23;
    const radius = Math.max(metrics.halfWidth * 0.23, 0.016);
    for (const sign of [-1, 1]) {
        addFacialPatch(
            THREE,
            group,
            hairMaterial,
            metrics,
            metrics.centerX + sign * xOffset,
            metrics.mouthY + metrics.height * 0.006,
            radius,
            [1.0, 0.30, 0.12],
            'MSKBA_Authored_Mustache',
            opacity,
        );
    }
}

function addJawPatches(THREE, group, hairMaterial, metrics, scale = 1, opacity = 1, centralOnly = false) {
    const w = metrics.halfWidth;
    const baseRadius = Math.max(w * 0.31, 0.020) * scale;
    const patches = centralOnly
        ? [
            [0, metrics.jawY, 0.94, 0.66],
            [0, metrics.chinY, 0.82, 0.70],
        ]
        : [
            [-0.54, metrics.jawY + metrics.height * 0.010, 0.78, 0.60],
            [0.54, metrics.jawY + metrics.height * 0.010, 0.78, 0.60],
            [-0.32, metrics.jawY - metrics.height * 0.004, 0.88, 0.66],
            [0.32, metrics.jawY - metrics.height * 0.004, 0.88, 0.66],
            [0, metrics.chinY + metrics.height * 0.006, 1.02, 0.72],
        ];

    patches.forEach(([xFactor, y, xScale, yScale]) => {
        addFacialPatch(
            THREE,
            group,
            hairMaterial,
            metrics,
            metrics.centerX + xFactor * w,
            y,
            baseRadius,
            [xScale, yScale, 0.13],
            'MSKBA_Authored_Beard',
            opacity,
        );
    });
}

function buildFacialHair(THREE, group, state, hairMaterial, metrics) {
    const facialHair = state.facialHair || 'none';
    if (facialHair === 'none') {
        return;
    }

    if (facialHair === 'stubble') {
        addMustache(THREE, group, hairMaterial, metrics, 0.34);
        addJawPatches(THREE, group, hairMaterial, metrics, 0.90, 0.30);
        return;
    }

    if (facialHair === 'mustache') {
        addMustache(THREE, group, hairMaterial, metrics);
        return;
    }

    if (facialHair === 'goatee') {
        addMustache(THREE, group, hairMaterial, metrics);
        addJawPatches(THREE, group, hairMaterial, metrics, 0.86, 1, true);
        return;
    }

    if (facialHair === 'short_beard') {
        addMustache(THREE, group, hairMaterial, metrics);
        addJawPatches(THREE, group, hairMaterial, metrics, 0.88, 1);
        return;
    }

    addMustache(THREE, group, hairMaterial, metrics);
    addJawPatches(THREE, group, hairMaterial, metrics, 1.02, 1);
    addFacialPatch(
        THREE,
        group,
        hairMaterial,
        metrics,
        metrics.centerX,
        metrics.chinY - metrics.height * 0.010,
        Math.max(metrics.halfWidth * 0.34, 0.022),
        [0.92, 1.06, 0.16],
        'MSKBA_Authored_Full_Beard_Chin',
    );
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
    if (!runtime.accessoryRoot) {
        return;
    }

    // Accessories are built directly in the authored modelRoot coordinate system.
    // Only mirror the body morphology scale; the modelRoot already owns height scale.
    runtime.accessoryRoot.scale.set(
        runtime.bodyWidthScale || 1,
        1,
        runtime.bodyDepthScale || 1,
    );
}

export function applyAuthoredBodyShape(runtime, state) {
    if (!runtime.model) {
        return;
    }

    // Cache real head anchors while the authored GLB is still at canonical X/Z scale.
    if (!runtime.authoredHeadMetrics) {
        collectHeadMetrics(runtime);
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
    const metrics = collectHeadMetrics(runtime);
    if (!metrics) {
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

    buildHair(runtime.THREE, root, state, hairMaterial, metrics);
    buildFacialHair(runtime.THREE, root, state, hairMaterial, metrics);
    syncAccessoryScale(runtime);
}

export function destroyAuthoredAccessories(runtime) {
    if (!runtime.accessoryRoot) {
        return;
    }

    disposeGroup(runtime.accessoryRoot);
    runtime.modelRoot?.remove(runtime.accessoryRoot);
    runtime.accessoryRoot = null;
    runtime.authoredHeadMetrics = null;
}
