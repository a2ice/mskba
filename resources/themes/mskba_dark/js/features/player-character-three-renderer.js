const THREE_VERSION = '0.184.0';
const THREE_MODULE_URL = `https://esm.sh/three@${THREE_VERSION}?target=es2022`;
const GLTF_LOADER_URL = `https://esm.sh/three@${THREE_VERSION}/examples/jsm/loaders/GLTFLoader.js?target=es2022`;

const MODEL_SOURCE_COMMIT = '3f97faf85e46d2f9a122b0a8b8d3ccc0af598f91';
const MODEL_BASE_URL = `https://cdn.jsdelivr.net/gh/kunalkushwaha/vsim@${MODEL_SOURCE_COMMIT}/packages/assets/library`;

const MODEL_URLS = {
    male: `${MODEL_BASE_URL}/man.glb`,
    female: `${MODEL_BASE_URL}/human.glb`,
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
    mskba_home: { primary: '#161816', accent: '#ef7d00' },
    mskba_light: { primary: '#e7e3d9', accent: '#ef7d00' },
    street_black: { primary: '#111312', accent: '#d9ddd8' },
    city_night: { primary: '#121928', accent: '#f18a19' },
};

const BODY_TYPE_MASS = {
    unspecified: 0,
    slim: -0.055,
    athletic: 0,
    muscular: 0.075,
    stocky: 0.11,
    large: 0.16,
};

const RUNTIME = new WeakMap();
let enginePromise = null;
const modelPromises = new Map();

function importRemoteModule(url) {
    return import(/* @vite-ignore */ url);
}

async function loadEngine() {
    if (!enginePromise) {
        enginePromise = Promise.all([
            importRemoteModule(THREE_MODULE_URL),
            importRemoteModule(GLTF_LOADER_URL),
        ]).then(([THREE, loaderModule]) => ({
            THREE,
            GLTFLoader: loaderModule.GLTFLoader,
        }));
    }

    return enginePromise;
}

function clamp(value, min, max) {
    return Math.min(max, Math.max(min, value));
}

function normalizeGender(gender) {
    return gender === 'female' ? 'female' : 'male';
}

function calculateBodyMassScale(state) {
    const heightCm = Number(state.heightCm) || 180;
    const weightKg = Number(state.weightKg) || 80;
    const heightMeters = Math.max(1.45, heightCm / 100);
    const bmi = weightKg / (heightMeters * heightMeters);
    const bmiContribution = clamp((bmi - 23) * 0.008, -0.08, 0.14);
    const typeContribution = BODY_TYPE_MASS[state.bodyType] ?? 0;

    return clamp(1 + bmiContribution + typeContribution, 0.86, 1.28);
}

function materialRole(object, material) {
    const value = `${object.name || ''} ${material?.name || ''}`.toLowerCase();

    if (/(skin|body|human|face|head|arm|leg|hand|foot)/.test(value)) {
        return 'skin';
    }

    if (/(shirt|jersey|cloth|clothes|short|trouser|pants|suit|uniform|top)/.test(value)) {
        return 'uniform';
    }

    if (/(shoe|sneaker|boot)/.test(value)) {
        return 'shoe';
    }

    return 'other';
}

function cloneModelMaterials(root) {
    root.traverse((object) => {
        if (!object.isMesh || !object.material) {
            return;
        }

        const materials = Array.isArray(object.material) ? object.material : [object.material];
        const cloned = materials.map((material) => {
            const copy = material.clone();
            copy.userData.playerCharacterRole = materialRole(object, material);
            copy.userData.playerCharacterBaseColor = copy.color?.clone?.() || null;
            copy.userData.playerCharacterBaseEmissive = copy.emissive?.clone?.() || null;
            return copy;
        });

        object.material = Array.isArray(object.material) ? cloned : cloned[0];
        object.castShadow = true;
        object.receiveShadow = true;
    });
}

function tintMaterial(THREE, material, state) {
    if (!material?.color) {
        return;
    }

    const role = material.userData.playerCharacterRole;
    const baseColor = material.userData.playerCharacterBaseColor;

    if (baseColor?.isColor) {
        material.color.copy(baseColor);
    }

    if (role === 'skin') {
        const target = new THREE.Color(SKIN_TONES[state.skinTone] || SKIN_TONES.warm);
        material.color.lerp(target, material.map ? 0.32 : 0.82);
    }

    if (role === 'uniform') {
        const kit = UNIFORM_TONES[state.uniformKit] || UNIFORM_TONES.mskba_home;
        const target = new THREE.Color(kit.primary);
        material.color.lerp(target, material.map ? 0.44 : 0.92);
    }

    if (role === 'shoe') {
        const kit = UNIFORM_TONES[state.uniformKit] || UNIFORM_TONES.mskba_home;
        material.color.lerp(new THREE.Color(kit.primary), material.map ? 0.24 : 0.7);
    }

    material.needsUpdate = true;
}

function applyMaterials(runtime, state) {
    if (!runtime.model) {
        return;
    }

    runtime.model.traverse((object) => {
        if (!object.isMesh || !object.material) {
            return;
        }

        const materials = Array.isArray(object.material) ? object.material : [object.material];
        materials.forEach((material) => tintMaterial(runtime.THREE, material, state));
    });
}

function measureAndNormalizeModel(runtime) {
    const { THREE, model } = runtime;

    model.rotation.set(0, -Math.PI / 2, 0);
    model.updateMatrixWorld(true);

    const box = new THREE.Box3().setFromObject(model);
    const size = box.getSize(new THREE.Vector3());
    const center = box.getCenter(new THREE.Vector3());

    runtime.modelBaseHeight = Math.max(size.y, 0.001);
    model.position.x -= center.x;
    model.position.y -= box.min.y;
    model.position.z -= center.z;
    model.updateMatrixWorld(true);
}

function applyBodyScale(runtime, state) {
    if (!runtime.modelRoot || !runtime.modelBaseHeight) {
        return;
    }

    const targetHeightMeters = clamp((Number(state.heightCm) || 180) / 100, 1.45, 2.5);
    const uniformScale = targetHeightMeters / runtime.modelBaseHeight;
    const massScale = calculateBodyMassScale(state);
    const depthScale = 1 + (massScale - 1) * 0.72;

    runtime.modelRoot.scale.set(
        uniformScale * massScale,
        uniformScale,
        uniformScale * depthScale,
    );

    runtime.targetHeightMeters = targetHeightMeters;
    runtime.massScale = massScale;
}

function selectIdleAnimation(gltf) {
    if (!gltf.animations?.length) {
        return null;
    }

    return gltf.animations.find((clip) => clip.name.toLowerCase() === 'idle')
        || gltf.animations.find((clip) => /idle/i.test(clip.name))
        || null;
}

function loadModel(engine, gender) {
    const normalizedGender = normalizeGender(gender);
    const cacheKey = `${normalizedGender}:${MODEL_URLS[normalizedGender]}`;

    if (!modelPromises.has(cacheKey)) {
        modelPromises.set(cacheKey, new Promise((resolve, reject) => {
            const loader = new engine.GLTFLoader();
            loader.load(
                MODEL_URLS[normalizedGender],
                resolve,
                undefined,
                reject,
            );
        }));
    }

    return modelPromises.get(cacheKey);
}

function createShadow(THREE) {
    const geometry = new THREE.CircleGeometry(0.42, 48);
    const material = new THREE.MeshBasicMaterial({
        color: 0x000000,
        transparent: true,
        opacity: 0.2,
        depthWrite: false,
    });
    const shadow = new THREE.Mesh(geometry, material);
    shadow.rotation.x = -Math.PI / 2;
    shadow.scale.y = 0.36;
    shadow.position.set(0, 0.004, 0.02);

    return shadow;
}

function updateCamera(runtime) {
    const { container, camera } = runtime;
    const width = Math.max(container.clientWidth, 1);
    const height = Math.max(container.clientHeight, 1);
    const aspect = width / height;
    const metricHeight = 2.5;
    const metricWidth = metricHeight * aspect;

    camera.left = -metricWidth / 2;
    camera.right = metricWidth / 2;
    camera.top = metricHeight;
    camera.bottom = 0;
    camera.near = 0.01;
    camera.far = 20;
    camera.updateProjectionMatrix();

    runtime.renderer.setSize(width, height, false);
}

function setStatus(stage, status, message) {
    stage.dataset.threeStatus = status;
    const statusNode = stage.querySelector('[data-player-character-three-status]');

    if (statusNode) {
        statusNode.textContent = message;
    }
}

function attachPointerRotation(runtime) {
    const element = runtime.renderer.domElement;
    let activePointer = null;
    let previousX = 0;

    element.addEventListener('pointerdown', (event) => {
        activePointer = event.pointerId;
        previousX = event.clientX;
        element.setPointerCapture?.(event.pointerId);
        runtime.dragging = true;
    });

    element.addEventListener('pointermove', (event) => {
        if (activePointer !== event.pointerId) {
            return;
        }

        const delta = event.clientX - previousX;
        previousX = event.clientX;
        runtime.targetYaw = clamp(runtime.targetYaw + delta * 0.008, -0.62, 0.62);
    });

    const release = (event) => {
        if (activePointer !== event.pointerId) {
            return;
        }

        activePointer = null;
        runtime.dragging = false;
        element.releasePointerCapture?.(event.pointerId);
    };

    element.addEventListener('pointerup', release);
    element.addEventListener('pointercancel', release);
}

function renderLoop(runtime) {
    if (runtime.destroyed) {
        return;
    }

    const now = performance.now();
    const delta = Math.min((now - runtime.lastFrameAt) / 1000, 0.05);
    runtime.lastFrameAt = now;

    if (runtime.visible && !document.hidden) {
        runtime.mixer?.update(delta);

        if (!runtime.dragging) {
            runtime.targetYaw += (runtime.defaultYaw - runtime.targetYaw) * Math.min(1, delta * 1.8);
        }

        runtime.currentYaw += (runtime.targetYaw - runtime.currentYaw) * Math.min(1, delta * 8);

        if (runtime.modelRoot) {
            runtime.modelRoot.rotation.y = runtime.currentYaw;
        }

        runtime.renderer.render(runtime.scene, runtime.camera);
    }

    runtime.animationFrame = requestAnimationFrame(() => renderLoop(runtime));
}

async function createRuntime(stage, state) {
    const container = stage.querySelector('[data-player-character-three]');

    if (!container) {
        throw new Error('Player character 3D container is missing.');
    }

    setStatus(stage, 'loading', 'Загружаем 3D-модель…');

    const engine = await loadEngine();
    const { THREE } = engine;
    const renderer = new THREE.WebGLRenderer({
        antialias: true,
        alpha: true,
        powerPreference: 'high-performance',
    });

    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 1.75));
    renderer.outputColorSpace = THREE.SRGBColorSpace;
    renderer.toneMapping = THREE.ACESFilmicToneMapping;
    renderer.toneMappingExposure = 1.02;
    renderer.shadowMap.enabled = true;
    renderer.shadowMap.type = THREE.PCFSoftShadowMap;
    renderer.domElement.className = 'account-player-character-three__canvas';
    renderer.domElement.setAttribute('aria-label', 'Интерактивная 3D-модель игрока. Проведите влево или вправо, чтобы повернуть.');

    container.replaceChildren(renderer.domElement);

    const scene = new THREE.Scene();
    const camera = new THREE.OrthographicCamera(-1, 1, 2.5, 0, 0.01, 20);
    camera.position.set(0.08, 1.25, 4.2);
    camera.lookAt(0, 1.18, 0);

    const hemisphere = new THREE.HemisphereLight(0xf7f2e8, 0x151815, 2.0);
    scene.add(hemisphere);

    const key = new THREE.DirectionalLight(0xfff3df, 3.0);
    key.position.set(2.2, 3.1, 3.8);
    key.castShadow = true;
    key.shadow.mapSize.set(1024, 1024);
    scene.add(key);

    const fill = new THREE.DirectionalLight(0xbac8ff, 1.1);
    fill.position.set(-2.4, 2.0, 2.0);
    scene.add(fill);

    const rim = new THREE.DirectionalLight(0xff9d42, 0.75);
    rim.position.set(-1.2, 2.8, -2.6);
    scene.add(rim);

    const modelRoot = new THREE.Group();
    modelRoot.rotation.y = -0.12;
    scene.add(modelRoot);
    scene.add(createShadow(THREE));

    const runtime = {
        THREE,
        stage,
        container,
        renderer,
        scene,
        camera,
        modelRoot,
        model: null,
        modelBaseHeight: null,
        mixer: null,
        resizeObserver: null,
        intersectionObserver: null,
        animationFrame: null,
        visible: true,
        destroyed: false,
        dragging: false,
        defaultYaw: -0.12,
        targetYaw: -0.12,
        currentYaw: -0.12,
        lastFrameAt: performance.now(),
        state,
    };

    updateCamera(runtime);

    runtime.resizeObserver = new ResizeObserver(() => updateCamera(runtime));
    runtime.resizeObserver.observe(container);

    runtime.intersectionObserver = new IntersectionObserver((entries) => {
        runtime.visible = entries.some((entry) => entry.isIntersecting);
    }, { threshold: 0.02 });
    runtime.intersectionObserver.observe(stage);

    attachPointerRotation(runtime);

    const sourceGltf = await loadModel(engine, state.gender);
    const model = sourceGltf.scene.clone(true);
    const animations = sourceGltf.animations || [];

    cloneModelMaterials(model);
    runtime.model = model;
    runtime.modelRoot.add(model);
    measureAndNormalizeModel(runtime);
    applyBodyScale(runtime, state);
    applyMaterials(runtime, state);

    const idleClip = selectIdleAnimation({ animations });

    if (idleClip) {
        runtime.mixer = new THREE.AnimationMixer(model);
        const action = runtime.mixer.clipAction(idleClip);
        action.reset().fadeIn(0.2).play();
    }

    stage.dataset.renderer = 'three-v1';
    setStatus(stage, 'ready', '3D · поверните персонажа мышью или пальцем');
    renderLoop(runtime);

    return runtime;
}

export async function mountPlayerCharacterThree(stage, state) {
    if (RUNTIME.has(stage)) {
        return RUNTIME.get(stage);
    }

    try {
        const runtime = await createRuntime(stage, state);
        RUNTIME.set(stage, runtime);
        return runtime;
    } catch (error) {
        console.error('Player Character 3D renderer failed to initialize.', error);
        stage.dataset.renderer = 'svg-fallback';
        setStatus(stage, 'error', '3D временно недоступно · показана резервная версия');
        return null;
    }
}

export function updatePlayerCharacterThree(stage, state) {
    const runtime = RUNTIME.get(stage);

    if (!runtime) {
        return;
    }

    runtime.state = state;
    applyBodyScale(runtime, state);
    applyMaterials(runtime, state);
}

export function destroyPlayerCharacterThree(stage) {
    const runtime = RUNTIME.get(stage);

    if (!runtime) {
        return;
    }

    runtime.destroyed = true;
    runtime.resizeObserver?.disconnect();
    runtime.intersectionObserver?.disconnect();
    cancelAnimationFrame(runtime.animationFrame);
    runtime.mixer?.stopAllAction();
    runtime.renderer.dispose();
    RUNTIME.delete(stage);
}
