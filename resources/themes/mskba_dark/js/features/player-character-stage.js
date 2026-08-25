import '../../css/pages/player-character-three.css';
import { mountPlayerCharacterThree, updatePlayerCharacterThree } from './player-character-three-renderer.js';

const DEFAULT_HAIRSTYLE = {
    male: 'male_fade',
    female: 'female_ponytail',
};

const THREE_RUNTIME = new WeakMap();

function parseNullableNumber(value) {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : null;
}

function characterField(form, key) {
    return form.querySelector(`[data-player-character-field="${key}"]`);
}

function normalizeGender(value) {
    return value === 'female' ? 'female' : 'male';
}

function readState(stage, form) {
    return {
        gender: normalizeGender(stage.dataset.gender),
        heightCm: parseNullableNumber(form.querySelector('[data-player-character-input="height"]')?.value),
        weightKg: parseNullableNumber(form.querySelector('[data-player-character-input="weight"]')?.value),
        bodyType: form.querySelector('[data-player-character-input="body-type"]')?.value || 'unspecified',
        skinTone: characterField(form, 'skin-tone')?.value || 'warm',
        hairstyle: characterField(form, 'hairstyle')?.value || DEFAULT_HAIRSTYLE[normalizeGender(stage.dataset.gender)],
        hairColor: characterField(form, 'hair-color')?.value || 'dark_brown',
        facialHair: characterField(form, 'facial-hair')?.value || 'none',
        uniformKit: characterField(form, 'uniform-kit')?.value || 'mskba_home',
    };
}

function syncChoiceButtons(configurator, field, value) {
    configurator.querySelectorAll(`[data-player-character-choice="${field}"]`).forEach((button) => {
        button.setAttribute('aria-pressed', button.dataset.value === value ? 'true' : 'false');
    });
}

function setCharacterField(form, configurator, field, value) {
    const input = characterField(form, field);

    if (!input) {
        return;
    }

    input.value = value;
    syncChoiceButtons(configurator, field, value);
}

function syncProfileGenderControls(stage, form, configurator) {
    const gender = normalizeGender(stage.dataset.gender);
    const hairstyleInput = characterField(form, 'hairstyle');
    const compatibleButtons = [];

    configurator.querySelectorAll('[data-player-character-choice="hairstyle"]').forEach((button) => {
        const compatible = button.dataset.characterGender === gender;
        button.hidden = !compatible;

        if (compatible) {
            compatibleButtons.push(button);
        }
    });

    const hairstyleIsCompatible = compatibleButtons.some((button) => button.dataset.value === hairstyleInput?.value);

    if (!hairstyleIsCompatible && hairstyleInput) {
        setCharacterField(
            form,
            configurator,
            'hairstyle',
            DEFAULT_HAIRSTYLE[gender] || compatibleButtons[0]?.dataset.value || '',
        );
    }

    const facialHairGroup = configurator.querySelector('[data-player-character-facial-hair-group]');

    if (facialHairGroup) {
        facialHairGroup.hidden = gender === 'female';
    }

    if (gender === 'female') {
        setCharacterField(form, configurator, 'facial-hair', 'none');
    }
}

function updateMetricScale(stage, state) {
    const previewHeightCm = state.heightCm ?? 180;
    const normalizedHeightCm = Math.min(250, Math.max(0, previewHeightCm));
    const heightPercent = normalizedHeightCm / 250 * 100;

    stage.dataset.hasHeight = state.heightCm === null ? 'false' : 'true';
    stage.style.setProperty('--player-height-percent', heightPercent.toFixed(4));

    const label = stage.querySelector('[data-player-character-height-label]');

    if (label) {
        label.textContent = state.heightCm === null ? 'Рост не указан' : `${state.heightCm} см`;
    }
}

function updateStatusText(stage, text) {
    const status = stage.closest('.account-player-character-visual')
        ?.querySelector('[data-player-character-three-status]');

    if (status) {
        status.textContent = text;
    }
}

function configureMetricViewport(runtime) {
    const resize = () => {
        const width = Math.max(runtime.container.clientWidth, 1);
        const height = Math.max(runtime.container.clientHeight, 1);

        // Literal metric viewport: 200 cm wide × 250 cm tall.
        // Camera is centered at world Y=1.25, therefore ±1.25 maps to Y=0..2.5.
        runtime.camera.left = -1;
        runtime.camera.right = 1;
        runtime.camera.top = 1.25;
        runtime.camera.bottom = -1.25;
        runtime.camera.near = 0.01;
        runtime.camera.far = 20;
        runtime.camera.position.set(0, 1.25, 4.2);
        runtime.camera.lookAt(0, 1.25, 0);
        runtime.camera.updateProjectionMatrix();
        runtime.renderer.setSize(width, height, false);
    };

    runtime.resizeObserver?.disconnect();
    runtime.resizeObserver = new ResizeObserver(resize);
    runtime.resizeObserver.observe(runtime.container);
    resize();
}

function visibleModelBox(runtime) {
    const box = new runtime.THREE.Box3();
    const objectBox = new runtime.THREE.Box3();
    let hasMesh = false;

    runtime.model?.traverse((object) => {
        if (!object.isMesh || object.visible === false || !object.geometry) {
            return;
        }

        objectBox.setFromObject(object);
        if (objectBox.isEmpty()) {
            return;
        }

        if (!hasMesh) {
            box.copy(objectBox);
            hasMesh = true;
        } else {
            box.union(objectBox);
        }
    });

    return hasMesh ? box : null;
}

function enforceMetricModel(runtime, state) {
    if (!runtime?.model || !runtime.modelRoot) {
        return;
    }

    runtime.modelRoot.updateMatrixWorld(true);
    runtime.model.updateMatrixWorld(true);

    let box = visibleModelBox(runtime);
    if (!box) {
        return;
    }

    const currentHeight = Math.max(box.max.y - box.min.y, 0.001);
    const targetHeight = Math.min(2.5, Math.max(1.45, (Number(state.heightCm) || 180) / 100));
    const correction = targetHeight / currentHeight;

    if (Math.abs(correction - 1) > 0.0005) {
        runtime.modelRoot.scale.multiplyScalar(correction);
        runtime.modelRoot.updateMatrixWorld(true);
        runtime.model.updateMatrixWorld(true);
        box = visibleModelBox(runtime);
    }

    if (!box) {
        return;
    }

    const scaleY = Math.max(Math.abs(runtime.modelRoot.scale.y), 0.0001);
    runtime.model.position.y += -box.min.y / scaleY;
    runtime.model.updateMatrixWorld(true);
}

function updateStage(stage, form, configurator) {
    const state = readState(stage, form);

    updateMetricScale(stage, state);
    updatePlayerCharacterThree(stage, state);

    const runtime = THREE_RUNTIME.get(stage);
    if (runtime) {
        enforceMetricModel(runtime, state);
    }

    stage.dispatchEvent(new CustomEvent('player-character:change', {
        bubbles: true,
        detail: state,
    }));

    return state;
}

function bindCharacterChoices(stage, form, configurator) {
    configurator.querySelectorAll('[data-player-character-choice]').forEach((button) => {
        button.addEventListener('click', () => {
            const field = button.dataset.playerCharacterChoice;
            const value = button.dataset.value;

            if (!field || !value) {
                return;
            }

            setCharacterField(form, configurator, field, value);
            updateStage(stage, form, configurator);
        });
    });
}

function bindPhysicalInputs(stage, form, configurator) {
    form.querySelectorAll('[data-player-character-input]').forEach((input) => {
        input.addEventListener('change', () => updateStage(stage, form, configurator));
        input.addEventListener('input', () => updateStage(stage, form, configurator));
    });
}

function waitUntilNearViewport(stage) {
    if (!('IntersectionObserver' in window)) {
        return Promise.resolve();
    }

    const rect = stage.getBoundingClientRect();
    const preloadDistance = 320;

    if (rect.top < window.innerHeight + preloadDistance && rect.bottom > -preloadDistance) {
        return Promise.resolve();
    }

    return new Promise((resolve) => {
        const observer = new IntersectionObserver((entries) => {
            if (!entries.some((entry) => entry.isIntersecting)) {
                return;
            }

            observer.disconnect();
            resolve();
        }, {
            rootMargin: `${preloadDistance}px 0px`,
            threshold: 0.01,
        });

        observer.observe(stage);
    });
}

async function bindPlayerCharacterStage(stage) {
    const form = stage.closest('form');
    const configurator = form?.querySelector('[data-player-character-configurator]');

    if (!form || !configurator) {
        return;
    }

    // The legacy SVG is no longer part of the runtime renderer path.
    stage.querySelector('[data-player-character-svg-fallback]')?.remove();

    syncProfileGenderControls(stage, form, configurator);
    bindCharacterChoices(stage, form, configurator);
    bindPhysicalInputs(stage, form, configurator);

    const initialState = updateStage(stage, form, configurator);

    await waitUntilNearViewport(stage);

    const runtime = await mountPlayerCharacterThree(stage, initialState);

    if (!runtime) {
        updateStatusText(stage, '3D временно недоступно');
        return;
    }

    THREE_RUNTIME.set(stage, runtime);
    configureMetricViewport(runtime);

    const state = readState(stage, form);
    updatePlayerCharacterThree(stage, state);
    enforceMetricModel(runtime, state);
    updateStatusText(stage, '3D · собственная MSKBA-база · поверните мышью или пальцем');
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-player-character-stage]').forEach(bindPlayerCharacterStage);
});
