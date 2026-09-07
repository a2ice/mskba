import '../../css/pages/home-event-location-mini-polish.css';

const FLOW_SELECTOR = '[data-home-flow="event"]';
const MINI_MODAL_SELECTOR = '.home-event-location-modal';
const ACTIVE_STEP_SELECTOR = '[data-home-location-active-step]';
const VENUE_INPUT_SELECTOR = '[data-venue-selector-input]';
const VENUE_VALUE_SELECTOR = '[data-venue-selector-value]';
const VENUE_CLEAR_SELECTOR = '[data-venue-selector-clear]';
const VENUE_LIST_SELECTOR = '[data-venue-selector-list]';

let lastCity = '';
let lastDistrict = '';
let geolocationPending = false;
let geolocationAdvanceQueued = false;
let pendingGeolocationStreet = '';
let geolocationStreetQueryStarted = false;
let suppressVenueReopenUntil = 0;
let lastVenuePrimeAt = 0;

function normalize(value) {
    return String(value || '')
        .replace(/\s+/g, ' ')
        .trim();
}

function normalized(value) {
    return normalize(value).toLocaleLowerCase('ru');
}

function eventFlow() {
    return document.querySelector(FLOW_SELECTOR);
}

function miniModal() {
    return document.querySelector(MINI_MODAL_SELECTOR);
}

function originalGeolocationButton() {
    return eventFlow()?.querySelector('[data-home-event-current-location]') || null;
}

function originalGeolocationStatus() {
    return eventFlow()?.querySelector('[data-home-event-location-status]') || null;
}

function selectedOptionText(select) {
    if (!select?.value) return '';
    return normalize(select.options?.[select.selectedIndex]?.textContent || '');
}

function compactDistrictLabel(value) {
    const text = normalize(value);
    if (!text) return '';
    const separator = text.indexOf('—');
    return separator > 0 ? normalize(text.slice(0, separator)) : text;
}

function isAddressReverseRequest(input) {
    const raw = typeof input === 'string'
        ? input
        : (input instanceof Request ? input.url : String(input || ''));

    try {
        const url = new URL(raw, window.location.origin);
        return url.pathname === '/integrations/address-reverse';
    } catch (_) {
        return raw.includes('/integrations/address-reverse');
    }
}

const nativeFetch = window.fetch.bind(window);
window.fetch = async (...args) => {
    const response = await nativeFetch(...args);

    if (geolocationPending && isAddressReverseRequest(args[0])) {
        response.clone().json().then((payload) => {
            pendingGeolocationStreet = normalize(payload?.suggestion?.street);
            geolocationStreetQueryStarted = false;
        }).catch(() => {
            pendingGeolocationStreet = '';
            geolocationStreetQueryStarted = false;
        });
    }

    return response;
};

function captureSummaryContext(modal) {
    if (!modal) return;

    const activeStep = modal.querySelector(ACTIVE_STEP_SELECTOR)?.dataset.homeLocationActiveStep || '';
    if (activeStep === 'city') {
        lastDistrict = '';
    }

    modal.querySelectorAll('.home-event-location__mini-summary').forEach((summary) => {
        const label = normalized(summary.querySelector('small')?.textContent);
        const value = normalize(summary.querySelector('strong')?.textContent);
        if (!value || /^не\s/i.test(value)) return;

        if (label === 'город') lastCity = value;
        if (label === 'район' || label === 'округ') lastDistrict = compactDistrictLabel(value);
    });

    const citySelect = modal.querySelector('[data-home-location-city]');
    if (citySelect?.value) lastCity = selectedOptionText(citySelect);

    const districtSelect = modal.querySelector('[data-home-location-district]');
    if (districtSelect?.value) lastDistrict = compactDistrictLabel(selectedOptionText(districtSelect));
}

function moveOtherTerritoriesLast(modal) {
    const select = modal?.querySelector('[data-home-location-district]');
    if (!select) return;

    const option = [...select.options].find((item) => normalized(item.textContent) === 'другие территории');
    if (!option || select.lastElementChild === option) return;

    select.append(option);
}

function injectCurrentLocation(modal) {
    const card = modal?.querySelector(`${ACTIVE_STEP_SELECTOR}[data-home-location-active-step="city"]`);
    if (!card || card.querySelector('[data-home-location-current-inline]')) return;

    const cityWrap = card.querySelector('[data-home-location-city]')?.closest('.home-event-location__select-wrap');
    if (!cityWrap) return;

    const block = document.createElement('div');
    block.className = 'home-event-location__current-inline';
    block.dataset.homeLocationCurrentInline = '';
    block.innerHTML = `
        <button type="button" class="btn btn--secondary" data-home-location-current-inline-button>
            <i class="ti ti-current-location"></i><span>Текущая локация</span>
        </button>
        <p class="home-event-location__current-inline-status" data-home-location-current-inline-status hidden></p>
        <div class="home-event-location__current-inline-separator"><span>или выберите город</span></div>
    `;

    const button = block.querySelector('[data-home-location-current-inline-button]');
    button?.addEventListener('click', () => {
        const original = originalGeolocationButton();
        if (!original || original.disabled) return;

        lastCity = '';
        lastDistrict = '';
        geolocationPending = true;
        geolocationAdvanceQueued = false;
        pendingGeolocationStreet = '';
        geolocationStreetQueryStarted = false;

        const label = button.querySelector('span');
        button.disabled = true;
        if (label) label.textContent = 'Определяем…';

        const inlineStatus = block.querySelector('[data-home-location-current-inline-status]');
        if (inlineStatus) {
            inlineStatus.textContent = 'Определяем местоположение…';
            inlineStatus.dataset.state = 'loading';
            inlineStatus.hidden = false;
        }

        original.click();
    });

    card.insertBefore(block, cityWrap);
}

function mirrorCurrentLocationStatus(modal) {
    if (!geolocationPending || !modal) return;

    const source = originalGeolocationStatus();
    const target = modal.querySelector('[data-home-location-current-inline-status]');
    const button = modal.querySelector('[data-home-location-current-inline-button]');
    if (!source || !target || source.hidden) return;

    const message = source.textContent || '';
    const state = source.dataset.state || 'info';
    if (target.textContent !== message) target.textContent = message;
    if (target.dataset.state !== state) target.dataset.state = state;
    if (target.hidden) target.hidden = false;

    if (source.dataset.state === 'error') {
        geolocationPending = false;
        pendingGeolocationStreet = '';
        geolocationStreetQueryStarted = false;
        if (button) {
            if (button.disabled) button.disabled = false;
            const label = button.querySelector('span');
            if (label && label.textContent !== 'Текущая локация') label.textContent = 'Текущая локация';
        }
    }
}

function advanceCurrentLocationToFinal(modal) {
    if (!geolocationPending || geolocationAdvanceQueued || !modal) return;

    const activeStep = modal.querySelector(ACTIVE_STEP_SELECTOR)?.dataset.homeLocationActiveStep || '';
    const isCurrentLocation = [...modal.querySelectorAll('.home-event-location__source-summary strong')]
        .some((node) => normalized(node.textContent) === 'текущая локация');

    if (!isCurrentLocation || activeStep !== 'radius') return;

    const next = modal.querySelector('[data-home-location-mini-next]');
    if (!next || next.disabled) return;

    geolocationAdvanceQueued = true;
    geolocationPending = false;
    queueMicrotask(() => {
        next.click();
        geolocationAdvanceQueued = false;
    });
}

function applyGeolocationStreet(modal) {
    if (!pendingGeolocationStreet || !modal) return;

    const card = modal.querySelector(`${ACTIVE_STEP_SELECTOR}[data-home-location-active-step="final"]`);
    if (!card) return;

    const input = card.querySelector('[data-home-location-street-input]');
    const list = card.querySelector('[data-home-location-street-list]');
    if (!input || !list) return;

    if (!geolocationStreetQueryStarted) {
        input.value = pendingGeolocationStreet;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        geolocationStreetQueryStarted = true;
        return;
    }

    const options = [...list.querySelectorAll('button')];
    if (!options.length) return;

    const expected = normalized(pendingGeolocationStreet);
    const match = options.find((option) => {
        const label = normalize(option.querySelector('.address-suggest__label')?.textContent || option.textContent);
        return normalized(label) === expected;
    });

    if (!match) return;

    pendingGeolocationStreet = '';
    geolocationStreetQueryStarted = false;
    match.click();
}

function venueElements() {
    const flow = eventFlow();
    const selector = flow?.querySelector('[data-venue-selector]');
    if (!selector) return {};

    return {
        selector,
        input: selector.querySelector(VENUE_INPUT_SELECTOR),
        value: selector.querySelector(VENUE_VALUE_SELECTOR),
        clear: selector.querySelector(VENUE_CLEAR_SELECTOR),
        list: selector.querySelector(VENUE_LIST_SELECTOR),
    };
}

function activeStreetFilter() {
    const flow = eventFlow();
    const root = flow?.querySelector('.home-event-location');
    return normalize(root?.dataset.homeLocationStreet || '');
}

function venueListVisible(list) {
    return Boolean(list && !list.classList.contains('d-none'));
}

function canPrimeVenueFromStreet() {
    const { input, value, list } = venueElements();
    if (!input || !value || !list) return false;
    if (Date.now() < suppressVenueReopenUntil) return false;
    if (normalize(input.value)) return false;
    if (/^\d+$/.test(String(value.value || ''))) return false;
    if (!activeStreetFilter()) return false;
    if (venueListVisible(list)) return false;
    return true;
}

function primeVenueFromStreet() {
    if (!canPrimeVenueFromStreet()) return;
    const now = Date.now();
    if (now - lastVenuePrimeAt < 450) return;

    const { input } = venueElements();
    const street = activeStreetFilter();
    if (!input || !street) return;

    lastVenuePrimeAt = now;
    input.value = street;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.value = '';
}

function syncVenueClearControl() {
    const { input, value, clear, list } = venueElements();
    if (!input || !value || !clear || !list) return;
    if (/^\d+$/.test(String(value.value || ''))) return;
    if (!activeStreetFilter()) return;

    if (!normalize(input.value) && venueListVisible(list)) {
        clear.hidden = false;
        clear.disabled = false;
        clear.classList.remove('is-loading');
        clear.setAttribute('aria-label', 'Скрыть варианты площадок');
    }
}

function patchCompletionCard() {
    const flow = eventFlow();
    if (!flow) return;

    flow.querySelectorAll('.home-event-location__mini-complete').forEach((card) => {
        const strong = card.querySelector('span > strong');
        const small = card.querySelector('span > small');
        if (!strong || !small) return;

        if (normalize(strong.textContent) === 'Локация настроена') {
            strong.textContent = 'Локация установлена';
        }

        if (!lastCity || !lastDistrict) return;

        const venueValue = flow.querySelector('[data-venue-selector-value]')?.value || '';
        if (/^\d+$/.test(String(venueValue))) return;

        const parts = normalize(small.textContent)
            .split('·')
            .map((part) => normalize(part))
            .filter(Boolean);

        if (!parts.length || normalized(parts[0]) !== normalized(lastCity)) return;
        if (parts.some((part) => normalized(part) === normalized(lastDistrict))) return;

        parts.splice(1, 0, lastDistrict);
        small.textContent = parts.join(' · ');
    });
}

function syncMiniPolish() {
    const modal = miniModal();
    if (modal) {
        captureSummaryContext(modal);
        moveOtherTerritoriesLast(modal);
        injectCurrentLocation(modal);
        mirrorCurrentLocationStatus(modal);
        advanceCurrentLocationToFinal(modal);
        applyGeolocationStreet(modal);
    }
    patchCompletionCard();
    syncVenueClearControl();
}

const observer = new MutationObserver(() => {
    queueMicrotask(syncMiniPolish);
});

observer.observe(document.documentElement, {
    childList: true,
    subtree: true,
    attributes: true,
    attributeFilter: ['class', 'hidden', 'data-state', 'disabled'],
});

document.addEventListener('change', (event) => {
    const citySelect = event.target.closest?.('[data-home-location-city]');
    if (citySelect) {
        lastCity = selectedOptionText(citySelect);
        lastDistrict = '';
        return;
    }

    const districtSelect = event.target.closest?.('[data-home-location-district]');
    if (districtSelect) {
        lastDistrict = compactDistrictLabel(selectedOptionText(districtSelect));
    }
});

document.addEventListener('click', (event) => {
    const clear = event.target.closest?.(VENUE_CLEAR_SELECTOR);
    if (clear && eventFlow()?.contains(clear) && activeStreetFilter()) {
        suppressVenueReopenUntil = Date.now() + 500;
        return;
    }

    const input = event.target.closest?.(VENUE_INPUT_SELECTOR);
    if (!input || !eventFlow()?.contains(input)) return;
    window.setTimeout(primeVenueFromStreet, 0);
});

document.addEventListener('focusin', (event) => {
    const input = event.target.closest?.(VENUE_INPUT_SELECTOR);
    if (!input || !eventFlow()?.contains(input)) return;
    window.setTimeout(primeVenueFromStreet, 0);
});

syncMiniPolish();
