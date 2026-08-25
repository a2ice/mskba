import '../../css/pages/player-character-image.css';

const MAX_FACE_EDGE = 640;
const FACE_JPEG_QUALITY = 0.84;

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

function syncChoiceButtons(root, field, value) {
    root.querySelectorAll(`[data-player-character-choice="${field}"]`).forEach((button) => {
        button.setAttribute('aria-pressed', button.dataset.value === value ? 'true' : 'false');
    });
}

function setChoice(form, modal, field, value) {
    const input = characterField(form, field);
    if (!input) {
        return;
    }

    input.value = value;
    syncChoiceButtons(modal, field, value);
}

function renderRequestedInput(form) {
    return form.querySelector('[data-player-character-render-requested]');
}

function markRenderRequested(form) {
    const input = renderRequestedInput(form);
    if (input) {
        input.value = '1';
    }
}

function updateMetricStage(stage, form) {
    const height = parseNullableNumber(form.querySelector('[data-player-character-input="height"]')?.value);
    const normalized = height === null ? 180 : Math.min(250, Math.max(0, height));
    const percent = normalized / 250 * 100;

    stage.style.setProperty('--player-height-percent', percent.toFixed(4));
    stage.dataset.hasHeight = height === null ? 'false' : 'true';

    const marker = stage.querySelector('[data-player-character-height-marker]');
    const label = marker?.querySelector('[data-player-character-height-label]');
    if (!marker) {
        return;
    }

    if (height === null) {
        marker.hidden = true;
        marker.setAttribute('aria-expanded', 'false');
        return;
    }

    marker.hidden = false;
    marker.setAttribute('aria-label', `${height} см`);
    if (label) {
        label.textContent = `${height} см`;
    }
}

function showRenderState(stage, status, options = {}) {
    const generated = stage.querySelector('[data-player-character-generated]');
    const errorMessage = stage.querySelector('[data-player-character-render-error-message]');

    stage.dataset.renderStatus = status;

    if (status === 'ready' && generated) {
        const resultUrl = options.resultUrl || stage.dataset.renderResultUrl;
        if (resultUrl && generated.getAttribute('src') !== resultUrl) {
            generated.setAttribute('src', resultUrl);
        }
    }

    if (status === 'error' && errorMessage) {
        errorMessage.textContent = options.error
            || stage.dataset.renderError
            || 'Не удалось собрать игровой образ. Попробуйте изменить настройки.';
    }
}

function settleMockRender(stage) {
    if (stage.dataset.renderStatus !== 'generating') {
        return;
    }

    const readyAt = Date.parse(stage.dataset.renderReadyAt || '');
    if (!Number.isFinite(readyAt)) {
        return;
    }

    const settle = () => {
        if (stage.dataset.renderMode === 'error') {
            const message = 'Не удалось собрать игровой образ. Это тестовая ошибка — измените режим ответа и сохраните профиль ещё раз.';
            stage.dataset.renderError = message;
            showRenderState(stage, 'error', { error: message });
            return;
        }

        showRenderState(stage, 'ready', { resultUrl: stage.dataset.renderResultUrl });
    };

    const remaining = readyAt - Date.now();
    if (remaining <= 0) {
        settle();
        return;
    }

    window.setTimeout(settle, remaining);
}

function bindHeightMarker(stage) {
    const marker = stage.querySelector('[data-player-character-height-marker]');
    if (!marker) {
        return;
    }

    marker.addEventListener('click', (event) => {
        event.stopPropagation();
        marker.setAttribute(
            'aria-expanded',
            marker.getAttribute('aria-expanded') === 'true' ? 'false' : 'true',
        );
    });

    marker.addEventListener('blur', () => marker.setAttribute('aria-expanded', 'false'));
    marker.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            marker.setAttribute('aria-expanded', 'false');
            marker.blur();
        }
    });
}

function openModal(modal) {
    if (typeof modal.showModal === 'function') {
        if (!modal.open) {
            modal.showModal();
        }
        return;
    }

    modal.setAttribute('open', '');
}

function closeModal(modal) {
    if (typeof modal.close === 'function') {
        modal.close();
        return;
    }

    modal.removeAttribute('open');
}

function bindModal(stage, form, modal) {
    form.querySelectorAll('[data-player-character-open-modal]').forEach((button) => {
        button.addEventListener('click', () => openModal(modal));
    });

    modal.querySelectorAll('[data-player-character-close-modal]').forEach((button) => {
        button.addEventListener('click', () => closeModal(modal));
    });

    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeModal(modal);
        }
    });

    modal.querySelectorAll('[data-player-character-choice]').forEach((button) => {
        button.addEventListener('click', () => {
            const field = button.dataset.playerCharacterChoice;
            const value = button.dataset.value;
            if (!field || !value) {
                return;
            }
            setChoice(form, modal, field, value);
        });
    });

    modal.querySelector('[data-player-character-apply]')?.addEventListener('click', () => {
        markRenderRequested(form);
        closeModal(modal);
    });
}

function readFileAsDataUrl(file) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(reader.result);
        reader.onerror = () => reject(reader.error || new Error('read failed'));
        reader.readAsDataURL(file);
    });
}

function loadImage(dataUrl) {
    return new Promise((resolve, reject) => {
        const image = new Image();
        image.onload = () => resolve(image);
        image.onerror = () => reject(new Error('image failed'));
        image.src = dataUrl;
    });
}

async function normalizeFacePhoto(file) {
    if (!file || !/^image\/(jpeg|png|webp)$/.test(file.type)) {
        throw new Error('Выберите JPG, PNG или WebP.');
    }

    const dataUrl = await readFileAsDataUrl(file);
    const image = await loadImage(dataUrl);
    const ratio = Math.min(1, MAX_FACE_EDGE / Math.max(image.naturalWidth, image.naturalHeight));
    const width = Math.max(1, Math.round(image.naturalWidth * ratio));
    const height = Math.max(1, Math.round(image.naturalHeight * ratio));
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    const context = canvas.getContext('2d');
    if (!context) {
        throw new Error('Не удалось подготовить фото.');
    }

    context.drawImage(image, 0, 0, width, height);
    return canvas.toDataURL('image/jpeg', FACE_JPEG_QUALITY);
}

function bindFaceUpload(form, modal) {
    const input = modal.querySelector('[data-player-character-face-input]');
    const hidden = form.querySelector('[data-player-character-face-data]');
    const label = modal.querySelector('[data-player-character-face-label]');
    if (!input || !hidden) {
        return;
    }

    input.addEventListener('change', async () => {
        const file = input.files?.[0];
        if (!file) {
            return;
        }

        if (label) {
            label.textContent = 'Подготавливаем фото…';
        }

        try {
            hidden.value = await normalizeFacePhoto(file);
            if (label) {
                label.textContent = `Выбрано: ${file.name}`;
            }
            markRenderRequested(form);
        } catch (error) {
            hidden.value = '';
            input.value = '';
            if (label) {
                label.textContent = error instanceof Error ? error.message : 'Не удалось обработать фото.';
            }
        }
    });
}

function bindPhysicalInputs(stage, form) {
    form.querySelectorAll('[data-player-character-input]').forEach((input) => {
        const handle = () => {
            updateMetricStage(stage, form);
            markRenderRequested(form);
        };
        input.addEventListener('change', handle);
        input.addEventListener('input', handle);
    });
}

function bindPlayerCharacterStage(stage) {
    const form = stage.closest('form');
    const modal = form?.querySelector('[data-player-character-modal]');
    if (!form || !modal) {
        return;
    }

    updateMetricStage(stage, form);
    bindHeightMarker(stage);
    bindModal(stage, form, modal);
    bindFaceUpload(form, modal);
    bindPhysicalInputs(stage, form);
    showRenderState(stage, stage.dataset.renderStatus || 'idle');
    settleMockRender(stage);

    form.addEventListener('submit', () => {
        if (renderRequestedInput(form)?.value === '1') {
            showRenderState(stage, 'generating');
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-player-character-stage]').forEach(bindPlayerCharacterStage);
});
