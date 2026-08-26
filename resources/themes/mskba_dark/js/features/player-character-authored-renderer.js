import modelPart01 from './player-character-authored-model/part-01.js';
import modelPart02 from './player-character-authored-model/part-02.js';
import modelPart03 from './player-character-authored-model/part-03.js';
import {
    destroyPlayerCharacterThree as destroyLegacyRenderer,
    mountPlayerCharacterThree as mountLegacyRenderer,
    updatePlayerCharacterThree as updateLegacyRenderer,
} from './player-character-three-renderer.js';

const THREE_VERSION = '0.184.0';
const THREE_MODULE_URL = `https://esm.sh/three@${THREE_VERSION}?target=es2022`;
const GLTF_LOADER_URL = `https://esm.sh/three@${THREE_VERSION}/examples/jsm/loaders/GLTFLoader.js?target=es2022`;

// The viewport is literal metric space: X=-1..1 = 200 cm, Y=0..2.5 = 250 cm.
const SCENE_LEFT = -1;
const SCENE_RIGHT = 1;
const SCENE_BOTTOM = 0;
const SCENE_TOP = 2.5;

const SKIN_TONES = {
    porcelain: '#f1c7a9',
    light: '#ddb08e',
    warm: '#bd8360',
    tan: '#9c6749',
    brown: '#704731',
    deep: '#432a22',
};

const RUNTIME = new WeakMap();
let enginePromise = null;
let authoredModelBufferPromise = null;

function normalizeGender(gender) {
    return gender === 'female' ? 'female' : 'male';
}

function clamp(value, min, max) {
    return Math.min(max, Math.max(min, value));
}

function targetHeightMeters(state) {
    return clamp((Number(state.heightCm) || 180) / 100, 1.45, SCENE_TOP);
}

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

function base64ToBytes(value) {
    const binary = window.atob(value);
    const bytes = new Uint8Array(binary.length);

    for (let index = 0; index < binary.length; index += 1) {
        bytes[index] = binary.charCodeAt(index);
    }

    return bytes;
}

async function authoredModelBuffer() {
    if (!authoredModelBufferPromise) {
        authoredModelBufferPromise = (async () => {
            if (typeof DecompressionStream === 'undefined') {
                throw new Error('This browser cannot decompress the bundled Player Character model.');
            }

            const compressed = base64ToBytes(modelPart01 + modelPart02 + modelPart03);
            const stream = new Blob([compressed])
                .stream()
                .pipeThrough(new DecompressionStream('gzip'));

            return new Response(stream).arrayBuffer();
        })();
    }

    return authoredModelBufferPromise;
}

async function loadAuthoredModel(engine) {
    const buffer = await authoredModelBuffer();

    return new Promise((resolve, reject) => {
        const loader = new engine.GLTFLoader();
        loader.parse(buffer, '', resolve, reject);
    });
}

function setLifecycleStatus(stage, status, message = '') {
    stage.dataset.threeStatus = status;
    const errorNode = stage.closest('.account-player-character-visual')
        ?.querySelector('[data-player-character-error]');

    if (!errorNode) {
        return;
    }

    const hasError = status === 'error';
    errorNode.hidden = !hasError;
    errorNode.textContent = hasError ? message : '';
}

function createShadow(THREE) {
    const geometry = new THREE.CircleGeometry(0.38, 48);
    const material = new THREE.MeshBasicMaterial({
        color: 0x000000,
        transparent: true,
        opacity: 0.2,
        depthWrite: false,
    });
    const shadow = new THREE.Mesh(geometry, material);
    shadow.rotation.x = -Math.PI / 2;
    shadow.scale.y = 0.34;
    shadow.position.set(0, 0.004, 0.02);
    return shadow;
}

function prepareMaterials(runtime) {
    runtime.model.traverse((object) => {
        if (!object.isMesh) {
            return;
        }

        const previousMaterials = Array.isArray(object.material) ? object.material : [object.material];
        previousMaterials.filter(Boolean).forEach((material) => material.dispose?.());

        object.material = new runtime.THREE.MeshStandardMaterial({
            color: SKIN_TONES[runtime.state.skinTone] || SKIN_TONES.warm,
            roughness: 0.72,
            metalness: 0,
        });
        object.castShadow = true;
        object.receiveShadow = true;
    });
}

function applySkinTone(runtime, state) {
    const color = new runtime.THREE.Color(SKIN_TONES[state.skinTone] || SKIN_TONES.warm);

    runtime.model?.traverse((object) => {
        if (!object.isMesh || !object.material?.color) {
            return;
        }

        object.material.color.copy(color);
        object.material.needsUpdate = true;
    });
}

function measureAndNormalizeModel(runtime) {
    const { THREE, model, modelRoot } = runtime;

    modelRoot.position.set(0, 0, 0);
    modelRoot.scale.set(1, 1, 1);
    model.position.set(0, 0, 0);
    model.rotation.set(0, 0, 0);
    modelRoot.updateMatrixWorld(true);
    model.updateMatrixWorld(true);

    const box = new THREE.Box3().setFromObject(model);
    const size = box.getSize(new THREE.Vector3());
    const center = box.getCenter(new THREE.Vector3());

    runtime.modelBaseHeight = Math.max(size.y, 0.001);
    runtime.modelBaseWidth = Math.max(size.x, 0.001);

    // Put authored feet on the exact metric floor and center X/Z. The same uniform
    // scale is then applied to X/Y/Z, so the horizontal 200 cm scale remains real.
    model.position.set(-center.x, -box.min.y, -center.z);
    model.updateMatrixWorld(true);
}

function applyMetricHeight(runtime, state) {
    if (!runtime.modelRoot || !runtime.modelBaseHeight) {
        return;
    }

    const heightMeters = targetHeightMeters(state);
    const uniformScale = heightMeters / runtime.modelBaseHeight;

    runtime.modelRoot.scale.setScalar(uniformScale);
    runtime.modelRoot.position.set(0, 0, 0);
    runtime.targetHeightMeters = heightMeters;
    runtime.displayWidthMeters = runtime.modelBaseWidth * uniformScale;
}

function updateHeightMarker(runtime) {
    const marker = runtime.stage.querySelector('[data-player-character-height-marker]');
    const label = marker?.querySelector('[data-player-character-height-label]');
    const heightCm = Number(runtime.state?.heightCm);

    if (!marker || !Number.isFinite(heightCm) || heightCm <= 0) {
        if (marker) {
            marker.hidden = true;
            marker.setAttribute('aria-expanded', 'false');
        }
        return;
    }

    const heightMeters = clamp(heightCm / 100, SCENE_BOTTOM, SCENE_TOP);
    const point = new runtime.THREE.Vector3(-0.72, heightMeters, 0);
    runtime.camera.updateMatrixWorld(true);
    point.project(runtime.camera);

    const left = (point.x + 1) * 0.5 * runtime.container.clientWidth;
    const top = (1 - (point.y + 1) * 0.5) * runtime.container.clientHeight;

    marker.style.left = `${left.toFixed(2)}px`;
    marker.style.top = `${top.toFixed(2)}px`;
    marker.hidden = false;
    marker.setAttribute('aria-label', `${heightCm} см`);
    if (label) {
        label.textContent = `${heightCm} см`;
    }
}

function updateCamera(runtime) {
    const width = Math.max(runtime.container.clientWidth, 1);
    const height = Math.max(runtime.container.clientHeight, 1);
    const verticalCenter = (SCENE_TOP + SCENE_BOTTOM) / 2;

    runtime.camera.left = SCENE_LEFT;
    runtime.camera.right = SCENE_RIGHT;
    runtime.camera.top = (SCENE_TOP - SCENE_BOTTOM) / 2;
    runtime.camera.bottom = -(SCENE_TOP - SCENE_BOTTOM) / 2;
    runtime.camera.near = 0.01;
    runtime.camera.far = 20;
    runtime.camera.position.set(0, verticalCenter, 4.2);
    runtime.camera.lookAt(0, verticalCenter, 0);
    runtime.camera.updateProjectionMatrix();
    runtime.camera.updateMatrixWorld(true);
    runtime.renderer.setSize(width, height, false);
    updateHeightMarker(runtime);
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
        runtime.targetYaw = clamp(runtime.targetYaw + delta * 0.008, -0.85, 0.85);
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
        if (!runtime.dragging) {
            runtime.targetYaw += (runtime.defaultYaw - runtime.targetYaw) * Math.min(1, delta * 1.8);
        }

        runtime.currentYaw += (runtime.targetYaw - runtime.currentYaw) * Math.min(1, delta * 8);
        runtime.modelRoot.rotation.y = runtime.currentYaw;
        runtime.renderer.render(runtime.scene, runtime.camera);
    }

    runtime.animationFrame = requestAnimationFrame(() => renderLoop(runtime));
}

async function createRuntime(stage, state) {
    const container = stage.querySelector('[data-player-character-three]');
    if (!container) {
        throw new Error('Player character 3D container is missing.');
    }

    setLifecycleStatus(stage, 'loading');

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
    renderer.toneMappingExposure = 1.05;
    renderer.shadowMap.enabled = true;
    renderer.shadowMap.type = THREE.PCFSoftShadowMap;
    renderer.domElement.className = 'account-player-character-three__canvas';
    renderer.domElement.setAttribute('aria-label', 'Модель игрока. Проведите влево или вправо, чтобы повернуть.');
    container.replaceChildren(renderer.domElement);

    const scene = new THREE.Scene();
    const camera = new THREE.OrthographicCamera(-1, 1, 1.25, -1.25, 0.01, 20);

    scene.add(new THREE.HemisphereLight(0xf8f2e8, 0x151815, 1.8));

    const key = new THREE.DirectionalLight(0xfff2df, 2.55);
    key.position.set(2.2, 3.1, 3.8);
    key.castShadow = true;
    key.shadow.mapSize.set(1024, 1024);
    scene.add(key);

    const fill = new THREE.DirectionalLight(0xbac8ff, 0.9);
    fill.position.set(-2.4, 2.0, 2.0);
    scene.add(fill);

    const rim = new THREE.DirectionalLight(0xff9d42, 0.48);
    rim.position.set(-1.2, 2.8, -2.6);
    scene.add(rim);

    const modelRoot = new THREE.Group();
    scene.add(modelRoot);

    const shadow = createShadow(THREE);
    scene.add(shadow);

    const runtime = {
        ...engine,
        stage,
        container,
        renderer,
        scene,
        camera,
        modelRoot,
        shadow,
        model: null,
        modelBaseHeight: null,
        modelBaseWidth: null,
        resizeObserver: null,
        intersectionObserver: null,
        animationFrame: null,
        visible: true,
        destroyed: false,
        dragging: false,
        defaultYaw: 0,
        targetYaw: 0,
        currentYaw: 0,
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

    const gltf = await loadAuthoredModel(engine);
    runtime.model = gltf.scene;
    runtime.modelRoot.add(runtime.model);
    prepareMaterials(runtime);
    measureAndNormalizeModel(runtime);
    applyMetricHeight(runtime, state);
    applySkinTone(runtime, state);
    updateHeightMarker(runtime);

    setLifecycleStatus(stage, 'ready');
    renderLoop(runtime);
    return runtime;
}

export async function mountPlayerCharacterThree(stage, state) {
    if (normalizeGender(state.gender) === 'female') {
        return mountLegacyRenderer(stage, state);
    }

    if (RUNTIME.has(stage)) {
        return RUNTIME.get(stage);
    }

    try {
        const runtime = await createRuntime(stage, state);
        RUNTIME.set(stage, runtime);
        return runtime;
    } catch (error) {
        console.error('Authored Player Character renderer failed to initialize.', error);
        setLifecycleStatus(stage, 'error', 'Не удалось загрузить модель игрока. Попробуйте обновить страницу.');
        return null;
    }
}

export function updatePlayerCharacterThree(stage, state) {
    if (normalizeGender(state.gender) === 'female') {
        updateLegacyRenderer(stage, state);
        return;
    }

    const runtime = RUNTIME.get(stage);
    if (!runtime) {
        return;
    }

    runtime.state = state;
    applyMetricHeight(runtime, state);
    applySkinTone(runtime, state);
    updateHeightMarker(runtime);
}

export function destroyPlayerCharacterThree(stage) {
    const runtime = RUNTIME.get(stage);
    if (!runtime) {
        destroyLegacyRenderer(stage);
        return;
    }

    runtime.destroyed = true;
    runtime.resizeObserver?.disconnect();
    runtime.intersectionObserver?.disconnect();
    cancelAnimationFrame(runtime.animationFrame);

    runtime.model?.traverse((object) => {
        object.geometry?.dispose?.();
        const materials = Array.isArray(object.material) ? object.material : [object.material];
        materials.filter(Boolean).forEach((material) => material.dispose?.());
    });

    runtime.shadow?.geometry?.dispose?.();
    runtime.shadow?.material?.dispose?.();
    runtime.renderer.dispose();
    RUNTIME.delete(stage);
}
