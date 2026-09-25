import '../../css/pages/player-character-generation-ux.css';

const SKIP_PRICE_CONFIRM_KEY = 'mskba:player-character-generation:skip-price-confirm:v1';
const ACTIVE_GENERATION_KEY = 'mskba:player-character-generation:v1';
const nativeFetch = window.fetch.bind(window);
const confirmedClicks = new WeakSet();

const state = {
    teams: new Map(),
    withTeamLogo: false,
    activeGenerationIds: new Set(),
    activePrices: new Map(),
    terminalSeen: new Set(),
    historySection: null,
    historyStrip: null,
    logoControl: null,
};

function stageElement() {
    return document.querySelector('[data-player-character-stage]');
}

function formElement() {
    return stageElement()?.closest('form') || null;
}

function csrfToken() {
    const form = formElement();
    return form?.querySelector('input[name="_token"]')?.value
        || document.querySelector('meta[name="csrf-token"]')?.content
        || '';
}

function mutationUrl() {
    return stageElement()?.dataset.characterMutationUrl || null;
}

async function mutationRequest(payload) {
    const url = mutationUrl();
    if (!url) {
        throw new Error('Не удалось определить адрес операции.');
    }

    const response = await nativeFetch(url, {
        method: 'PATCH',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify(payload),
    });
    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        const validationMessage = data.errors
            ? Object.values(data.errors).flat()[0]
            : null;
        const error = new Error(validationMessage || data.message || 'Не удалось выполнить действие.');
        error.status = response.status;
        error.code = data.code || null;
        error.payload = data;
        throw error;
    }

    return data;
}

function formatRubles(minor) {
    const value = Number(minor);
    if (!Number.isFinite(value)) {
        return null;
    }

    return new Intl.NumberFormat('ru-RU', {
        style: 'currency',
        currency: 'RUB',
        maximumFractionDigits: value % 100 === 0 ? 0 : 2,
    }).format(value / 100);
}

function generationErrorMessage(payload = {}) {
    if (payload?.code !== 'insufficient_balance') {
        return payload?.message || 'Не удалось сгенерировать 2D-модель.';
    }

    const price = formatRubles(payload.price_minor);
    const available = formatRubles(payload.available_minor);

    if (price && available) {
        return `Недостаточно средств. Стоимость генерации — ${price}, доступно — ${available}.`;
    }

    return payload.message || 'Недостаточно средств для генерации модели.';
}

function toastStack() {
    let stack = document.querySelector('[data-player-generation-toast-stack]');
    if (stack) {
        return stack;
    }

    stack = document.createElement('div');
    stack.className = 'player-generation-toast-stack';
    stack.dataset.playerGenerationToastStack = '';
    document.body.append(stack);
    return stack;
}

function showToast(message, error = false) {
    if (!message) {
        return;
    }

    const toast = document.createElement('div');
    toast.className = `player-generation-toast${error ? ' is-error' : ''}`;
    toast.textContent = message;
    toastStack().append(toast);

    requestAnimationFrame(() => toast.classList.add('is-visible'));
    window.setTimeout(() => {
        toast.classList.remove('is-visible');
        window.setTimeout(() => toast.remove(), 180);
    }, 4600);
}

function ensureGenerationOverlay() {
    const stage = stageElement();
    if (!stage) {
        return null;
    }

    let overlay = stage.querySelector('[data-player-generation-overlay]');
    if (overlay) {
        return overlay;
    }

    overlay = document.createElement('div');
    overlay.className = 'account-player-generation-overlay';
    overlay.dataset.playerGenerationOverlay = '';
    overlay.hidden = true;
    overlay.innerHTML = `
        <div class="account-player-generation-overlay__content">
            <span class="account-player-generation-overlay__spinner" aria-hidden="true"></span>
            <span>Генерируем новый образ…</span>
        </div>
    `;
    stage.append(overlay);
    return overlay;
}

function setGenerationBusy(busy) {
    const overlay = ensureGenerationOverlay();
    if (overlay) {
        overlay.hidden = !busy;
    }
}

function readStoredActiveGeneration() {
    try {
        const data = JSON.parse(window.localStorage.getItem(ACTIVE_GENERATION_KEY) || 'null');
        return typeof data?.generationId === 'string' && data.generationId !== ''
            ? data.generationId
            : null;
    } catch {
        return null;
    }
}

function isGenerationActive(generationId) {
    if (!generationId) {
        return false;
    }

    return state.activeGenerationIds.has(generationId)
        || readStoredActiveGeneration() === generationId;
}

function applyTwoDimensionalImage(url) {
    const stage = stageElement();
    const image = stage?.querySelector('[data-player-character-two-image]');
    if (!image || !url) {
        return;
    }

    image.src = url;
    image.classList.remove('is-placeholder');
    image.removeAttribute('data-placeholder-gender');
}

function ensureHistorySection() {
    if (state.historySection?.isConnected) {
        return state.historySection;
    }

    const stage = stageElement();
    if (!stage) {
        return null;
    }

    const section = document.createElement('section');
    section.className = 'account-player-generation-history';
    section.dataset.playerGenerationHistory = '';
    section.innerHTML = `
        <div class="account-player-generation-history__heading">
            <span class="account-player-generation-history__title">История 2D-генераций</span>
        </div>
        <div class="account-player-generation-history__strip" data-player-generation-history-strip></div>
    `;
    stage.insertAdjacentElement('afterend', section);
    state.historySection = section;
    state.historyStrip = section.querySelector('[data-player-generation-history-strip]');
    return section;
}

function pendingHistoryItem(generationId = '') {
    const item = document.createElement('div');
    item.className = 'account-player-generation-history__item';
    if (generationId) {
        item.dataset.generationId = generationId;
    }
    item.innerHTML = `
        <div class="account-player-generation-history__pending">
            <span class="account-player-generation-history__pending-spinner" aria-hidden="true"></span>
            <span>Создаётся…</span>
        </div>
    `;
    return item;
}

function openLightbox(url) {
    if (!url) {
        return;
    }

    const modal = document.createElement('div');
    modal.className = 'player-generation-modal';
    modal.innerHTML = `
        <div class="player-generation-modal__dialog player-generation-lightbox__dialog" role="dialog" aria-modal="true" aria-label="Просмотр генерации">
            <img src="${url}" alt="Сгенерированный 2D-персонаж">
            <div class="player-generation-modal__actions">
                <button type="button" data-player-generation-lightbox-use>Показать в блоке</button>
                <button type="button" data-player-generation-lightbox-close>Закрыть</button>
            </div>
        </div>
    `;

    const close = () => modal.remove();
    modal.querySelector('[data-player-generation-lightbox-close]')?.addEventListener('click', close);
    modal.querySelector('[data-player-generation-lightbox-use]')?.addEventListener('click', () => {
        applyTwoDimensionalImage(url);
        close();
    });
    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            close();
        }
    });
    document.body.append(modal);
}

async function setPrimaryGeneration(generationId, imageUrl) {
    try {
        const result = await mutationRequest({
            mutation: 'generation_primary',
            generation_id: generationId,
        });
        applyTwoDimensionalImage(result.image_url || imageUrl);
        showToast('Главное изображение обновлено.');
        await refreshHistory(false);
    } catch (error) {
        showToast(error?.message || 'Не удалось выбрать главное изображение.', true);
    }
}

function renderHistory(payload, applyPrimary = false) {
    ensureHistorySection();
    const strip = state.historyStrip;
    if (!strip) {
        return;
    }

    strip.replaceChildren();
    const generations = Array.isArray(payload?.generations) ? payload.generations : [];
    let primaryImageUrl = null;

    generations.forEach((generation) => {
        if (generation.status === 'pending' || generation.status === 'processing') {
            strip.append(pendingHistoryItem(generation.generation_id));
            return;
        }

        if (generation.status !== 'completed' || !generation.image_url) {
            return;
        }

        const item = document.createElement('div');
        item.className = 'account-player-generation-history__item';
        item.dataset.generationId = generation.generation_id;

        const thumb = document.createElement('button');
        thumb.type = 'button';
        thumb.className = `account-player-generation-history__thumb${generation.is_primary ? ' is-primary' : ''}`;
        thumb.title = 'Открыть изображение';

        const image = document.createElement('img');
        image.src = generation.image_url;
        image.alt = '';
        image.loading = 'lazy';
        thumb.append(image);

        const badges = document.createElement('span');
        badges.className = 'account-player-generation-history__badges';
        if (generation.is_primary) {
            const badge = document.createElement('span');
            badge.className = 'account-player-generation-history__badge account-player-generation-history__badge--primary';
            badge.textContent = 'Главное';
            badges.append(badge);
            primaryImageUrl = generation.image_url;
        }
        if (generation.is_new) {
            const badge = document.createElement('span');
            badge.className = 'account-player-generation-history__badge';
            badge.textContent = 'Новое';
            badges.append(badge);
        }
        thumb.append(badges);
        thumb.addEventListener('click', () => openLightbox(generation.image_url));
        item.append(thumb);

        const action = document.createElement('button');
        action.type = 'button';
        action.className = 'account-player-generation-history__action';
        action.textContent = generation.is_primary ? 'Главное' : 'Сделать главным';
        action.disabled = Boolean(generation.is_primary);
        if (!generation.is_primary) {
            action.addEventListener('click', () => setPrimaryGeneration(generation.generation_id, generation.image_url));
        }
        item.append(action);
        strip.append(item);
    });

    const storedActiveId = readStoredActiveGeneration();
    if (storedActiveId && !strip.querySelector(`[data-generation-id="${CSS.escape(storedActiveId)}"]`)) {
        strip.prepend(pendingHistoryItem(storedActiveId));
    }

    if (applyPrimary && primaryImageUrl) {
        applyTwoDimensionalImage(primaryImageUrl);
    }

    state.historySection.hidden = strip.children.length === 0;
}

async function refreshHistory(applyPrimary = false) {
    try {
        const payload = await mutationRequest({ mutation: 'generation_history' });
        renderHistory(payload, applyPrimary);
    } catch {
        // History is progressive enhancement and must never block the player form.
    }
}

function createLogoControl() {
    const form = formElement();
    const teamSelect = form?.querySelector('[data-player-character-team]');
    if (!teamSelect) {
        return null;
    }

    if (state.logoControl?.isConnected) {
        return state.logoControl;
    }

    const control = document.createElement('div');
    control.className = 'account-player-generation-logo-control';
    control.dataset.playerGenerationLogoControl = '';
    control.innerHTML = `
        <span class="account-player-generation-logo-control__label">Логотип команды на форме</span>
        <div class="account-player-generation-logo-control__choices" role="group" aria-label="Логотип команды на форме">
            <button type="button" class="account-player-generation-logo-control__choice" data-player-generation-logo-choice="1">С логотипом команды</button>
            <button type="button" class="account-player-generation-logo-control__choice" data-player-generation-logo-choice="0">Без логотипа команды</button>
        </div>
        <p class="account-player-generation-logo-control__hint" data-player-generation-logo-hint></p>
    `;

    const insertionPoint = teamSelect.closest('.form-group') || teamSelect.parentElement;
    insertionPoint?.insertAdjacentElement('afterend', control);
    control.querySelectorAll('[data-player-generation-logo-choice]').forEach((button) => {
        button.addEventListener('click', () => {
            if (button.disabled) {
                return;
            }
            state.withTeamLogo = button.dataset.playerGenerationLogoChoice === '1';
            syncLogoControl();
        });
    });

    state.logoControl = control;
    teamSelect.addEventListener('change', syncLogoControl);
    return control;
}

function selectedTeamLogoAvailability() {
    const teamId = Number(formElement()?.querySelector('[data-player-character-team]')?.value || 0);
    return teamId > 0 ? state.teams.get(teamId) : null;
}

function syncLogoControl() {
    const control = createLogoControl();
    if (!control) {
        return;
    }

    const team = selectedTeamLogoAvailability();
    const buttons = [...control.querySelectorAll('[data-player-generation-logo-choice]')];
    const hint = control.querySelector('[data-player-generation-logo-hint]');

    if (!team) {
        state.withTeamLogo = false;
        buttons.forEach((button) => { button.disabled = true; });
        if (hint) {
            hint.textContent = 'Сначала выберите команду.';
        }
    } else if (!team.has_logo) {
        state.withTeamLogo = false;
        buttons.forEach((button) => { button.disabled = true; });
        if (hint) {
            hint.textContent = 'У выбранной команды нет логотипа.';
        }
    } else {
        buttons.forEach((button) => { button.disabled = false; });
        if (hint) {
            hint.textContent = '';
        }
    }

    buttons.forEach((button) => {
        const value = button.dataset.playerGenerationLogoChoice === '1';
        button.setAttribute('aria-pressed', value === state.withTeamLogo ? 'true' : 'false');
    });
}

async function loadPreferences() {
    try {
        const payload = await mutationRequest({ mutation: 'generation_preferences' });
        state.teams = new Map(
            (Array.isArray(payload.teams) ? payload.teams : [])
                .map((team) => [Number(team.id), team]),
        );

        const select = formElement()?.querySelector('[data-player-character-team]');
        const storedTeamId = payload.generation_team_id ? String(payload.generation_team_id) : '';
        if (select && storedTeamId && [...select.options].some((option) => option.value === storedTeamId)) {
            if (select.value !== storedTeamId) {
                select.value = storedTeamId;
                select.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }

        state.withTeamLogo = Boolean(payload.with_team_logo);
    } catch {
        state.teams = new Map();
        state.withTeamLogo = false;
    }

    syncLogoControl();
}

function priceModal(priceMinor) {
    return new Promise((resolve) => {
        const formatted = formatRubles(priceMinor) || '—';
        const modal = document.createElement('div');
        modal.className = 'player-generation-modal';
        modal.innerHTML = `
            <div class="player-generation-modal__dialog" role="dialog" aria-modal="true" aria-label="Подтверждение стоимости генерации">
                <h4>Подтвердите генерацию</h4>
                <p>Стоимость данной операции — <strong>${formatted}</strong></p>
                <label class="player-generation-modal__check">
                    <input type="checkbox" data-player-generation-skip-price>
                    <span>Больше не показывать</span>
                </label>
                <div class="player-generation-modal__actions">
                    <button type="button" data-player-generation-cancel>Отмена</button>
                    <button type="button" data-player-generation-confirm>Продолжить</button>
                </div>
            </div>
        `;

        const finish = (accepted) => {
            const skip = Boolean(modal.querySelector('[data-player-generation-skip-price]')?.checked);
            modal.remove();
            resolve({ accepted, skip });
        };

        modal.querySelector('[data-player-generation-cancel]')?.addEventListener('click', () => finish(false));
        modal.querySelector('[data-player-generation-confirm]')?.addEventListener('click', () => finish(true));
        modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                finish(false);
            }
        });
        document.body.append(modal);
    });
}

async function confirmGenerationClick(button) {
    if (window.localStorage.getItem(SKIP_PRICE_CONFIRM_KEY) === '1') {
        confirmedClicks.add(button);
        button.click();
        return;
    }

    try {
        const quote = await mutationRequest({ mutation: 'generation_quote' });
        const result = await priceModal(quote.price_minor);
        if (!result.accepted) {
            return;
        }

        if (result.skip) {
            window.localStorage.setItem(SKIP_PRICE_CONFIRM_KEY, '1');
        }

        confirmedClicks.add(button);
        button.click();
    } catch (error) {
        showToast(error?.message || 'Не удалось получить стоимость генерации.', true);
    }
}

document.addEventListener('click', (event) => {
    const button = event.target instanceof Element
        ? event.target.closest('[data-player-character-generate]')
        : null;
    if (!button) {
        return;
    }

    if (confirmedClicks.has(button)) {
        confirmedClicks.delete(button);
        return;
    }

    event.preventDefault();
    event.stopImmediatePropagation();
    void confirmGenerationClick(button);
}, true);

function requestUrl(input) {
    if (typeof input === 'string') {
        return input;
    }
    if (input instanceof URL) {
        return input.href;
    }
    if (input instanceof Request) {
        return input.url;
    }
    return '';
}

function isGenerationStatusRequest(input) {
    const value = requestUrl(input);
    if (!value) {
        return false;
    }

    try {
        const url = new URL(value, window.location.origin);
        return url.origin === window.location.origin
            && /\/participation\/player\/generations\/[^/]+\/?$/.test(url.pathname);
    } catch {
        return false;
    }
}

async function handleGenerationResponse(response, generationRequest = false) {
    const payload = await response.clone().json().catch(() => ({}));

    if (generationRequest) {
        if (!response.ok) {
            setGenerationBusy(false);
            showToast(`Операция не успешна: ${generationErrorMessage(payload)}`, true);
            void refreshHistory(false);
            return;
        }

        if (payload.status === 'pending' && payload.generation_id) {
            state.activeGenerationIds.add(payload.generation_id);
            state.activePrices.set(payload.generation_id, Number(payload.price_minor) || 0);
            setGenerationBusy(true);
            void refreshHistory(false);
            return;
        }

        if (payload.status === 'generated') {
            setGenerationBusy(false);
            const price = formatRubles(payload.price_minor);
            showToast(`Операция успешна${price ? `, списано ${price}` : ''}.`);
            void refreshHistory(false);
        }
        return;
    }

    const generationId = payload?.generation_id;
    if (!generationId || !isGenerationActive(generationId) || state.terminalSeen.has(generationId)) {
        return;
    }

    if (payload.status === 'completed') {
        state.terminalSeen.add(generationId);
        state.activeGenerationIds.delete(generationId);
        setGenerationBusy(false);
        const price = formatRubles(payload.price_minor || state.activePrices.get(generationId));
        state.activePrices.delete(generationId);
        showToast(`Операция успешна${price ? `, списано ${price}` : ''}.`);
        void refreshHistory(false);
    } else if (payload.status === 'failed') {
        state.terminalSeen.add(generationId);
        state.activeGenerationIds.delete(generationId);
        state.activePrices.delete(generationId);
        setGenerationBusy(false);
        showToast(`Операция не успешна: ${payload.message || 'Не удалось сгенерировать 2D-модель.'}`, true);
        void refreshHistory(false);
    }
}

window.fetch = async (...args) => {
    const init = args[1] || {};
    const formData = init.body instanceof FormData ? init.body : null;
    const isGenerationRequest = formData?.get('mutation') === 'generate_2d';
    const isStatus = isGenerationStatusRequest(args[0]);

    if (isGenerationRequest) {
        formData.set('generation_with_team_logo', state.withTeamLogo ? '1' : '0');
        setGenerationBusy(true);
        ensureHistorySection();
        if (state.historyStrip && !state.historyStrip.querySelector('.account-player-generation-history__pending')) {
            state.historyStrip.prepend(pendingHistoryItem());
            state.historySection.hidden = false;
        }
    }

    try {
        const response = await nativeFetch(...args);
        if (isGenerationRequest || isStatus) {
            void handleGenerationResponse(response, isGenerationRequest);
        }
        return response;
    } catch (error) {
        if (isGenerationRequest) {
            setGenerationBusy(false);
            showToast(`Операция не успешна: ${error?.message || 'Не удалось выполнить генерацию.'}`, true);
            void refreshHistory(false);
        }
        throw error;
    }
};

document.addEventListener('DOMContentLoaded', () => {
    if (!stageElement()) {
        return;
    }

    ensureGenerationOverlay();
    ensureHistorySection();
    createLogoControl();

    const activeGenerationId = readStoredActiveGeneration();
    if (activeGenerationId) {
        state.activeGenerationIds.add(activeGenerationId);
        setGenerationBusy(true);
    }

    void loadPreferences();
    void refreshHistory(true);
});
