import './player-character-generation-ux.js';

const STORAGE_KEY = 'mskba:player-character-generation:v1';
const POLL_INTERVAL_MS = 4000;
const MAXIMUM_ATTEMPTS = 300;
const originalFetch = window.fetch.bind(window);
let activeStatusUrl = null;

function normalizedStatusUrl(value) {
    if (typeof value !== 'string' || value.trim() === '') {
        return null;
    }

    try {
        const url = new URL(value, window.location.origin);
        if (url.origin !== window.location.origin) {
            return null;
        }

        return url.href;
    } catch {
        return null;
    }
}

function latestCompletedStatusUrl(stage) {
    const mutationUrl = normalizedStatusUrl(stage?.dataset.characterMutationUrl);
    if (!mutationUrl) {
        return null;
    }

    const url = new URL(mutationUrl);
    const originalPath = url.pathname;
    url.pathname = originalPath.replace(/\/profile\/?$/, '/generations/latest');
    url.search = '';
    url.hash = '';

    return url.pathname === originalPath ? null : url.href;
}

function readStoredGeneration() {
    try {
        const payload = JSON.parse(window.localStorage.getItem(STORAGE_KEY) || 'null');
        const statusUrl = normalizedStatusUrl(payload?.statusUrl);

        if (!statusUrl || typeof payload?.generationId !== 'string' || payload.generationId === '') {
            return null;
        }

        return {
            generationId: payload.generationId,
            statusUrl,
        };
    } catch {
        return null;
    }
}

function storeGeneration(payload) {
    const statusUrl = normalizedStatusUrl(payload?.status_url);
    const generationId = typeof payload?.generation_id === 'string' ? payload.generation_id : '';

    if (payload?.status !== 'pending' || !statusUrl || generationId === '') {
        return;
    }

    window.localStorage.setItem(STORAGE_KEY, JSON.stringify({ generationId, statusUrl }));
}

function clearStoredGeneration(generationId) {
    const current = readStoredGeneration();
    if (current && current.generationId !== generationId) {
        return;
    }

    window.localStorage.removeItem(STORAGE_KEY);
}

function setStageBusy(stage, busy) {
    stage.dataset.renderBusy = busy ? 'true' : 'false';

    const loading = stage.querySelector('[data-player-character-loading]');
    if (loading) {
        loading.hidden = !busy;
    }

    const generationOverlay = stage.querySelector('[data-player-generation-overlay]');
    if (generationOverlay) {
        generationOverlay.hidden = !busy;
    }

    const form = stage.closest('form');
    const generate = form?.querySelector('[data-player-character-generate]');
    if (generate) {
        generate.disabled = busy;
        generate.setAttribute('aria-busy', busy ? 'true' : 'false');
    }

    const renderSwitch = stage.closest('.account-player-character-visual')
        ?.querySelector('[data-player-character-render-switch]');
    renderSwitch?.querySelectorAll('[data-player-character-render-mode]').forEach((button) => {
        button.disabled = busy;
    });
}

function setStageError(stage, message = '') {
    const errorNode = stage.closest('form')?.querySelector('[data-player-character-error]');
    if (!errorNode) {
        return;
    }

    errorNode.textContent = message;
    errorNode.hidden = message === '';
}

function applyCompletedImage(stage, payload) {
    const image = stage.querySelector('[data-player-character-two-image]');
    if (!image || !payload?.image_url) {
        return;
    }

    image.src = payload.image_url;
    image.classList.remove('is-placeholder');
    image.removeAttribute('data-placeholder-gender');
}

async function restoreLatestCompletedImage(stage) {
    const statusUrl = latestCompletedStatusUrl(stage);
    if (!statusUrl) {
        return;
    }

    try {
        const response = await originalFetch(statusUrl, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });

        if (response.status === 404) {
            return;
        }

        if (!response.ok) {
            return;
        }

        const payload = await response.json().catch(() => ({}));
        if (payload.status === 'completed') {
            applyCompletedImage(stage, payload);
        }
    } catch {
        // Restoring a previous image must not block the account page.
    }
}

function wait(milliseconds) {
    return new Promise((resolve) => window.setTimeout(resolve, milliseconds));
}

async function pollGeneration(record) {
    if (!record || activeStatusUrl === record.statusUrl) {
        return;
    }

    const stage = document.querySelector('[data-player-character-stage]');
    if (!stage) {
        return;
    }

    activeStatusUrl = record.statusUrl;
    setStageBusy(stage, true);
    setStageError(stage, '');

    try {
        for (let attempt = 0; attempt < MAXIMUM_ATTEMPTS; attempt += 1) {
            if (attempt > 0) {
                await wait(POLL_INTERVAL_MS);
            }

            const response = await originalFetch(record.statusUrl, {
                method: 'GET',
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
                if (response.status === 404) {
                    clearStoredGeneration(record.generationId);
                    return;
                }

                throw new Error(payload.message || 'Не удалось проверить статус генерации.');
            }

            if (payload.status === 'completed') {
                applyCompletedImage(stage, payload);
                clearStoredGeneration(record.generationId);
                return;
            }

            if (payload.status === 'failed') {
                clearStoredGeneration(record.generationId);
                throw new Error(payload.message || 'Не удалось сгенерировать 2D-модель.');
            }
        }

        clearStoredGeneration(record.generationId);
        throw new Error('Генерация не завершилась в отведённое время. Попробуйте запустить её ещё раз.');
    } catch (error) {
        setStageError(stage, error?.message || 'Не удалось сгенерировать 2D-модель.');
    } finally {
        activeStatusUrl = null;
        setStageBusy(stage, false);
    }
}

async function rememberPendingGeneration(args, response) {
    try {
        const init = args[1] || {};
        if (!(init.body instanceof FormData) || init.body.get('mutation') !== 'generate_2d' || response.status !== 202) {
            return;
        }

        const payload = await response.clone().json();
        storeGeneration(payload);
    } catch {
        // Synchronization is progressive enhancement; the initiating tab keeps its own polling flow.
    }
}

window.fetch = async (...args) => {
    const response = await originalFetch(...args);
    void rememberPendingGeneration(args, response);

    return response;
};

window.addEventListener('storage', (event) => {
    if (event.key !== STORAGE_KEY || !event.newValue) {
        return;
    }

    void pollGeneration(readStoredGeneration());
});

document.addEventListener('DOMContentLoaded', async () => {
    const stage = document.querySelector('[data-player-character-stage]');
    if (!stage) {
        return;
    }

    await restoreLatestCompletedImage(stage);
    void pollGeneration(readStoredGeneration());
});
