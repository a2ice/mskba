import '../../css/pages/home-event-location-mini-polish.css';

const FLOW_SELECTOR = '[data-home-flow="event"]';
const MINI_MODAL_SELECTOR = '.home-event-location-modal';
const ACTIVE_STEP_SELECTOR = '[data-home-location-active-step]';

let lastCity = '';
let lastDistrict = '';
let geolocationPending = false;
let geolocationAdvanceQueued = false;

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
    if (!source || !target) return;

    if (source.hidden) return;

    target.textContent = source.textContent || '';
    target.dataset.state = source.dataset.state || 'info';
    target.hidden = false;

    if (source.dataset.state === 'error') {
        geolocationPending = false;
        if (button) {
            button.disabled = false;
            const label = button.querySelector('span');
            if (label) label.textContent = 'Текущая локация';
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
        injectCurrentLocation(modal);
        mirrorCurrentLocationStatus(modal);
        advanceCurrentLocationToFinal(modal);
    }
    patchCompletionCard();
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

syncMiniPolish();
