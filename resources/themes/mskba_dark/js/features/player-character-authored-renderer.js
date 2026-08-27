import authoredFemaleModelUrl from '../../models/player-character/mskba-female-player-v1.glb?url';
import authoredMaleModelUrl from '../../models/player-character/mskba-male-player-v1.glb?url';

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

const MODEL_URLS = {
    female: authoredFemaleModelUrl,
    male: authoredMaleModelUrl,
};

const RUNTIME = new WeakMap();
let enginePromise = null;

function normalizeGender(gender) {
    return gender === 'female' ? 'female' : 'male';
}

function clamp(value, min, max) {
    return Math.min(max, Math.max(min, value));
}

function targetHeightMeters(state) {
    return clamp((Number(state.heightCm) || 180) / 100, 1.45, SCENE_TOP);
}

async function loadEngine() {
    if (!enginePromise) {
        enginePromise = Promise.all([
            import('three'),
            import('three/examples/jsm/loaders/GLTFLoader.js'),
        ]).then(([THREE, loaderModule]) => ({
            THREE,
            GLTFLoader: loaderModule.GLTFLoader,
        }));
    }

    return enginePromise;
}

async function loadAuthoredModel(engine, gender) {
    return new engine.GLTFLoader().loadAsync(MODEL_URLS[normalizeGender(gender)]);
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
    const createMaterial = (sourceMaterial) => {
        const sourceName = sourceMaterial?.name || '';
        const role = sourceName === 'MSKBA_Hair'
            ? 'hair'
            : sourceName === 'MSKBA_Beard'
                ? 'beard'
                : sourceName === 'MSKBA_Shoes_Primary'
                    ? 'shoes-primary'
                    : sourceName === 'MSKBA_Shoes_Accent'
                        ? 'shoes-accent'
                        : sourceName === 'MSKBA_Socks'
                            ? 'socks'
                            : sourceName === 'MSKBA_Uniform_Base'
                                ? 'uniform-base'
                                : sourceName === 'MSKBA_Uniform_Trim'
                                    ? 'uniform-trim'
                                    : 'skin';
        const color = role === 'skin'
            ? SKIN_TONES[runtime.state.skinTone] || SKIN_TONES.warm
            : role === 'shoes-primary' || role === 'shoes-accent'
                ? '#eeeeeb'
                : role === 'socks'
                    ? '#a8adaf'
                    : role === 'uniform-base'
                        ? '#555b60'
                        : role === 'uniform-trim'
                            ? '#08090a'
                            : '#3a271f';
        const result = new runtime.THREE.MeshStandardMaterial({
            name: sourceName || `MSKBA_${role}`,
            color,
            roughness: role === 'skin'
                ? 0.72
                : role.startsWith('shoes-')
                    ? role === 'shoes-primary' ? 0.42 : 0.48
                    : role === 'socks'
                        ? 0.78
                        : role === 'uniform-base' ? 0.38 : role === 'uniform-trim' ? 0.54 : 0.9,
            metalness: 0,
        });
        result.userData.playerCharacterRole = role;
        return result;
    };

    runtime.model.traverse((object) => {
        if (!object.isMesh) {
            return;
        }

        object.material = Array.isArray(object.material)
            ? object.material.map(createMaterial)
            : createMaterial(object.material);
        object.castShadow = true;
        object.receiveShadow = true;
    });

    runtime.model.traverse((object) => {
        if (
            !object.isMesh
            && (object.name.startsWith('MSKBA_Hair_') || object.name.startsWith('MSKBA_Beard_'))
        ) {
            object.visible = false;
        }
    });
}

function applySkinTone(runtime, state) {
    const color = new runtime.THREE.Color(SKIN_TONES[state.skinTone] || SKIN_TONES.warm);

    runtime.model?.traverse((object) => {
        if (
            !object.isMesh
            || !object.material?.color
            || object.material.userData.playerCharacterRole !== 'skin'
        ) {
            return;
        }

        object.material.color.copy(color);
        object.material.needsUpdate = true;
    });
}

function measureAndNormalizeModel(runtime) {
    const { THREE, model, modelRoot } = runtime;
    const body = model.getObjectByName('Body');
    if (!body?.isMesh) {
        throw new Error('Player character asset does not contain the required Body mesh.');
    }

    modelRoot.position.set(0, 0, 0);
    modelRoot.scale.set(1, 1, 1);
    model.position.set(0, 0, 0);
    model.rotation.set(0, 0, 0);
    modelRoot.updateMatrixWorld(true);
    model.updateMatrixWorld(true);

    const box = new THREE.Box3().setFromObject(body);
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
        runtime.targetYaw += delta * 0.008;
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

    const gltf = await loadAuthoredModel(engine, state.gender);
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
