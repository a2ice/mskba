import $ from 'jquery';
import { getCurrentCoordinates, reverseGeocode } from './current-location.js';
import '../../css/pages/home-event-location.css';
import '../../css/pages/home-event-location-conditional.css';

const LOCATION_OPTIONS_URL = '/home/location-options';
const LOCATION_SENTINEL_ANY = '__home_location_any__';
const LOCATION_SENTINEL_FILTERS = '__home_location_filters__';
const GEOLOCATION_ERROR = 'Не удалось получить геопозицию. Попробуйте ещё раз или выберите город вручную';
const DEFAULT_RADIUS_KM = 3;
const RADIUS_OPTIONS = [1, 3, 5, 10];

function normalize(value) {
    return String(value || '').replace(/\s+/g, ' ').trim();
}

function normalizedSearch(value) {
    return normalize(value).toLocaleLowerCase('ru');
}

function initHomeEventLocation(flow) {
    if (!flow || flow.dataset.homeFlow !== 'event') {
        return null;
    }

    const panel = flow.querySelector('[data-home-flow-panel="search"]');
    const locationStage = panel?.querySelector('.home-flow-event-venue-stage');
    const venueSelector = locationStage?.querySelector('[data-venue-selector]');
    const venueInput = venueSelector?.querySelector('[data-venue-selector-input]');
    const venueValue = venueSelector?.querySelector('[data-venue-selector-value]');
    const venueClear = venueSelector?.querySelector('[data-venue-selector-clear]');
    const legacyMetroSelect = venueSelector?.querySelector('[data-venue-selector-metro-filter]');
    const legacyMetroToggle = venueSelector?.querySelector('[data-venue-selector-metro-toggle]');
    const legacyMetroPanel = venueSelector?.querySelector('[data-venue-selector-metro-panel]');
    const visibleLocationStep = panel?.querySelector('[data-home-event-search-step="2"]');

    if (!panel || !locationStage || !venueSelector || !venueInput || !venueValue || !visibleLocationStep) {
        return null;
    }

    const baseVenueSearchUrl = venueSelector.dataset.searchUrl || '/venues/search';

    if (legacyMetroToggle) {
        legacyMetroToggle.hidden = true;
    }
    if (legacyMetroPanel) {
        legacyMetroPanel.hidden = true;
    }
    if (legacyMetroSelect) {
        legacyMetroSelect.closest('.ts-wrapper')?.setAttribute('hidden', 'hidden');
        legacyMetroSelect.hidden = true;
    }

    const root = document.createElement('div');
    root.className = 'home-event-location home-event-location--conditional';

    const modeGrid = document.createElement('div');
    modeGrid.className = 'home-event-location__modes';
    modeGrid.innerHTML = `
        <button type="button" class="home-event-location__mode is-selected" data-home-event-location-mode="any" aria-pressed="true">
            <i class="ti ti-adjustments-off"></i><span><strong>Не важно</strong><small>Искать без ограничения по локации</small></span>
        </button>
        <button type="button" class="home-event-location__mode" data-home-event-location-mode="specific" aria-pressed="false">
            <i class="ti ti-map-pin"></i><span><strong>Указать локацию</strong><small>Город, район, метро, радиус, улица или площадка</small></span>
        </button>
    `;

    const specific = document.createElement('div');
    specific.className = 'home-event-location__specific';
    specific.hidden = true;

    const geolocationRow = document.createElement('div');
    geolocationRow.className = 'home-event-location__geolocation';
    geolocationRow.innerHTML = `
        <button type="button" class="btn btn--secondary" data-home-event-current-location>
            <i class="ti ti-current-location"></i><span>Текущая локация</span>
        </button>
        <p class="home-event-location__status" data-home-event-location-status hidden></p>
    `;

    const mini = document.createElement('div');
    mini.className = 'home-event-location__mini home-event-location__mini--conditional';
    mini.dataset.homeLocationMiniWizard = '';

    const stack = document.createElement('div');
    stack.className = 'home-event-location__mini-stack';
    mini.append(stack);
    specific.append(geolocationRow, mini);
    root.append(modeGrid, specific);

    locationStage.replaceChildren(root);

    const state = {
        mode: 'any',
        miniActive: false,
        loaded: false,
        loading: false,
        options: null,
        path: [],
        index: 0,
        source: 'manual',
        cityId: null,
        cityAny: false,
        moscowMode: null,
        districtId: null,
        districtAny: false,
        metro: null,
        anchor: null,
        radiusKm: DEFAULT_RADIUS_KM,
        street: '',
        streetSuggestion: null,
        venueId: null,
        venueLabel: '',
    };

    let streetTimer = null;
    let streetRequest = 0;
    let suppressVenueChange = false;

    const modeButtons = [...modeGrid.querySelectorAll('[data-home-event-location-mode]')];
    const geolocationButton = geolocationRow.querySelector('[data-home-event-current-location]');
    const status = geolocationRow.querySelector('[data-home-event-location-status]');

    function city() {
        return state.options?.cities?.find((item) => Number(item.id) === Number(state.cityId)) || null;
    }

    function district() {
        return city()?.districts?.find((item) => Number(item.id) === Number(state.districtId)) || null;
    }

    function isMoscow() {
        const selected = city();
        return normalizedSearch(selected?.name) === 'москва'
            || normalizedSearch(selected?.alias) === 'moscow';
    }

    function hasRadius() {
        return state.anchor
            && Number.isFinite(Number(state.anchor.latitude))
            && Number.isFinite(Number(state.anchor.longitude))
            && Number(state.radiusKm) > 0;
    }

    function currentStep() {
        return state.path[state.index] || null;
    }

    function resetVenue() {
        state.venueId = null;
        state.venueLabel = '';
        if (/^\d+$/.test(String(venueValue.value || ''))) {
            suppressVenueChange = true;
            venueClear?.click();
            suppressVenueChange = false;
        }
    }

    function resetStreetAndVenue() {
        state.street = '';
        state.streetSuggestion = null;
        resetVenue();
    }

    function resetAfterCity() {
        state.moscowMode = null;
        state.districtId = null;
        state.districtAny = false;
        state.metro = null;
        state.anchor = null;
        state.radiusKm = DEFAULT_RADIUS_KM;
        resetStreetAndVenue();
    }

    function resetAfterMoscowMode() {
        state.districtId = null;
        state.districtAny = false;
        state.metro = null;
        state.anchor = null;
        state.radiusKm = DEFAULT_RADIUS_KM;
        resetStreetAndVenue();
    }

    function clearManualGeography() {
        state.cityId = null;
        state.cityAny = false;
        state.moscowMode = null;
        state.districtId = null;
        state.districtAny = false;
        state.metro = null;
        resetStreetAndVenue();
    }

    function manualPathAfterCity() {
        if (state.cityAny || !state.cityId) {
            return ['city', 'final'];
        }
        if (isMoscow()) {
            return ['city', 'moscow-mode'];
        }
        return (city()?.districts || []).length > 0
            ? ['city', 'district', 'final']
            : ['city', 'final'];
    }

    function rebuildMoscowPath() {
        if (!isMoscow()) {
            state.path = manualPathAfterCity();
            return;
        }

        if (state.moscowMode === 'metro') {
            state.path = state.metro
                ? ['city', 'moscow-mode', 'metro', 'radius', 'final']
                : ['city', 'moscow-mode', 'metro'];
            return;
        }
        if (state.moscowMode === 'district') {
            state.path = ['city', 'moscow-mode', 'district', 'final'];
            return;
        }
        if (state.moscowMode === 'any') {
            state.path = ['city', 'moscow-mode', 'final'];
            return;
        }
        state.path = ['city', 'moscow-mode'];
    }

    function stepComplete(step) {
        if (step === 'city') {
            return state.cityAny || Boolean(state.cityId);
        }
        if (step === 'moscow-mode') {
            return ['metro', 'district', 'any'].includes(state.moscowMode);
        }
        if (step === 'district') {
            return state.districtAny || Boolean(state.districtId);
        }
        if (step === 'metro') {
            return Boolean(state.metro && state.anchor);
        }
        if (step === 'radius') {
            return Boolean(hasRadius());
        }
        if (step === 'final') {
            return true;
        }
        return false;
    }

    function canSkip(step) {
        return ['city', 'moscow-mode', 'district', 'metro', 'final'].includes(step);
    }

    function summaryFor(step) {
        if (step === 'city') {
            return ['Город', state.cityAny ? 'Не важно' : (city()?.name || 'Выбран')];
        }
        if (step === 'moscow-mode') {
            return ['Способ поиска', {
                metro: 'Рядом с метро',
                district: 'По округу',
                any: 'Без уточнения',
            }[state.moscowMode] || 'Не выбрано'];
        }
        if (step === 'district') {
            const item = district();
            return [isMoscow() ? 'Округ' : 'Район', state.districtAny
                ? 'Не важно'
                : (item?.short_name || item?.name || 'Выбран')];
        }
        if (step === 'metro') {
            return ['Метро', state.metro?.name || 'Не выбрано'];
        }
        if (step === 'radius') {
            return ['Радиус', `До ${state.radiusKm} км`];
        }
        if (step === 'final') {
            const parts = [];
            if (state.street) parts.push(state.street);
            if (state.venueId && state.venueLabel) parts.push(state.venueLabel);
            return ['Улица / площадка', parts.length ? parts.join(' · ') : 'Без уточнения'];
        }
        return ['Локация', 'Выбрана'];
    }

    function locationLabel() {
        if (state.mode === 'any') {
            return 'Не важно';
        }
        if (state.venueId && state.venueLabel) {
            return state.venueLabel;
        }

        const parts = [];
        if (state.source === 'current') {
            parts.push('Текущая локация');
        } else if (state.cityId) {
            parts.push(city()?.name || 'Город');
        }
        if (state.moscowMode === 'district' && state.districtId) {
            const item = district();
            parts.push(item?.short_name || item?.name || 'Округ');
        }
        if (state.moscowMode === 'metro' && state.metro) {
            parts.push(`м. ${state.metro.name}`);
        }
        if (hasRadius()) {
            parts.push(`до ${state.radiusKm} км`);
        }
        if (state.street) {
            parts.push(state.street);
        }
        return parts.length ? parts.join(' · ') : 'Локация указана';
    }

    function radiusSearchUrl() {
        if (!hasRadius()) {
            return baseVenueSearchUrl;
        }
        const lat = Number(state.anchor.latitude).toFixed(7);
        const lng = Number(state.anchor.longitude).toFixed(7);
        return `/home/venue-radius-search/${encodeURIComponent(lat)}/${encodeURIComponent(lng)}/${encodeURIComponent(String(state.radiusKm))}`;
    }

    function syncFilterDataset() {
        const radiusMode = state.mode === 'specific' && hasRadius()
            && (state.source === 'current' || state.moscowMode === 'metro');
        const administrativeMode = state.mode === 'specific' && !radiusMode;

        root.dataset.homeLocationFilterMode = radiusMode
            ? 'radius'
            : (administrativeMode ? 'administrative' : 'none');
        root.dataset.homeLocationCityId = administrativeMode && state.cityId ? String(state.cityId) : '';
        root.dataset.homeLocationDistrictId = administrativeMode && state.districtId ? String(state.districtId) : '';
        root.dataset.homeLocationLatitude = radiusMode ? String(state.anchor.latitude) : '';
        root.dataset.homeLocationLongitude = radiusMode ? String(state.anchor.longitude) : '';
        root.dataset.homeLocationRadiusKm = radiusMode ? String(state.radiusKm) : '';
        root.dataset.homeLocationStreet = state.street || '';
        root.dataset.homeLocationMetroId = radiusMode && state.metro ? String(state.metro.id) : '';

        venueSelector.dataset.searchUrl = radiusMode ? radiusSearchUrl() : baseVenueSearchUrl;

        if (administrativeMode && state.cityId && !state.cityAny) {
            venueSelector.dataset.locationCityFilter = city()?.name || '';
        } else {
            delete venueSelector.dataset.locationCityFilter;
        }

        if (state.street) {
            venueSelector.dataset.locationStreetFilter = state.street;
        } else {
            delete venueSelector.dataset.locationStreetFilter;
        }
    }

    function syncOuterValue() {
        syncFilterDataset();
        if (/^\d+$/.test(String(venueValue.value || '')) && state.venueId) {
            return;
        }

        if (state.mode === 'any') {
            venueValue.value = LOCATION_SENTINEL_ANY;
        } else if (state.mode === 'specific') {
            venueValue.value = LOCATION_SENTINEL_FILTERS;
        } else {
            venueValue.value = '';
        }
    }

    function updateModeButtons() {
        modeButtons.forEach((button) => {
            const selected = button.dataset.homeEventLocationMode === state.mode;
            button.classList.toggle('is-selected', selected);
            button.setAttribute('aria-pressed', String(selected));
        });
        specific.hidden = state.mode !== 'specific';
    }

    function createSummary(step, pathIndex) {
        const [label, value] = summaryFor(step);
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'home-event-location__mini-summary';
        button.innerHTML = '<span class="home-event-location__mini-summary-index"></span><span class="home-event-location__mini-summary-body"></span><i class="ti ti-pencil"></i>';
        button.querySelector('.home-event-location__mini-summary-index').textContent = String(pathIndex + 1);
        const body = button.querySelector('.home-event-location__mini-summary-body');
        const small = document.createElement('small');
        const strong = document.createElement('strong');
        small.textContent = label;
        strong.textContent = value;
        body.append(small, strong);
        button.addEventListener('click', () => {
            state.miniActive = true;
            state.index = pathIndex;
            render();
            focusCurrent();
        });
        return button;
    }

    function activeCard() {
        const step = currentStep();
        const card = document.createElement('section');
        card.className = 'home-event-location__mini-active-card';
        card.dataset.homeLocationActiveStep = step || '';

        if (step === 'city') renderCityStep(card);
        else if (step === 'moscow-mode') renderMoscowModeStep(card);
        else if (step === 'district') renderDistrictStep(card);
        else if (step === 'metro') renderMetroStep(card);
        else if (step === 'radius') renderRadiusStep(card);
        else if (step === 'final') renderFinalStep(card);

        return card;
    }

    function stepHead(card, eyebrow, title, description = '') {
        const head = document.createElement('div');
        head.className = 'home-event-location__substep-head';
        const small = document.createElement('small');
        const strong = document.createElement('strong');
        small.textContent = eyebrow;
        strong.textContent = title;
        head.append(small, strong);
        if (description) {
            const span = document.createElement('span');
            span.textContent = description;
            head.append(span);
        }
        card.append(head);
    }

    function renderCityStep(card) {
        stepHead(card, 'Локация', 'Выберите город');
        const label = document.createElement('label');
        label.className = 'home-event-location__select-wrap';
        label.innerHTML = '<span>Город</span>';
        const select = document.createElement('select');
        select.className = 'form-select';
        select.dataset.homeLocationCity = '';
        select.append(new Option('Выбрать город', ''));
        (state.options?.cities || []).forEach((item) => select.add(new Option(item.name, String(item.id))));
        select.value = state.cityId ? String(state.cityId) : '';
        select.addEventListener('change', () => {
            const value = Number(select.value);
            if (!value) return;
            state.source = 'manual';
            state.cityId = value;
            state.cityAny = false;
            resetAfterCity();
            state.path = manualPathAfterCity();
            state.index = 0;
            render();
        });
        label.append(select);
        card.append(label);
    }

    function renderMoscowModeStep(card) {
        stepHead(card, 'Москва', 'Как удобнее искать?', 'Округ и метро — альтернативные сценарии и не ограничивают друг друга.');
        const grid = document.createElement('div');
        grid.className = 'home-event-location__branch-grid';
        const choices = [
            ['metro', 'ti ti-train', 'Рядом с метро', 'Станция и радиус поиска'],
            ['district', 'ti ti-map-2', 'По округу', 'Административный округ Москвы'],
            ['any', 'ti ti-adjustments-off', 'Не важно', 'Только Москва без дополнительного ограничения'],
        ];
        choices.forEach(([value, icon, title, description]) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'home-event-location__branch-choice';
            button.classList.toggle('is-selected', state.moscowMode === value);
            button.innerHTML = `<i class="${icon}"></i><span><strong>${title}</strong><small>${description}</small></span>`;
            button.addEventListener('click', () => {
                state.moscowMode = value;
                resetAfterMoscowMode();
                state.moscowMode = value;
                rebuildMoscowPath();
                state.index = 1;
                render();
            });
            grid.append(button);
        });
        card.append(grid);
    }

    function renderDistrictStep(card) {
        const moscow = isMoscow();
        stepHead(card, moscow ? 'Москва' : (city()?.name || 'Город'), moscow ? 'Выберите округ' : 'Выберите район');
        const label = document.createElement('label');
        label.className = 'home-event-location__select-wrap';
        const caption = document.createElement('span');
        caption.textContent = moscow ? 'Округ' : 'Район';
        const select = document.createElement('select');
        select.className = 'form-select';
        select.dataset.homeLocationDistrict = '';
        select.append(new Option(moscow ? 'Выбрать округ' : 'Выбрать район', ''));
        (city()?.districts || []).forEach((item) => {
            const text = item.short_name ? `${item.short_name} — ${item.name}` : item.name;
            select.add(new Option(text, String(item.id)));
        });
        select.value = state.districtId ? String(state.districtId) : '';
        select.addEventListener('change', () => {
            const value = Number(select.value);
            if (!value) return;
            state.districtId = value;
            state.districtAny = false;
            resetStreetAndVenue();
            render();
        });
        label.append(caption, select);
        card.append(label);
    }

    function renderMetroStep(card) {
        stepHead(card, 'Москва · метро', 'Выберите станцию', 'Начните вводить название станции.');
        const wrap = document.createElement('div');
        wrap.className = 'home-event-location__metro-predictive';
        const inputWrap = document.createElement('div');
        inputWrap.className = 'address-suggest__input-wrap predictive-search__input-wrap';
        const input = document.createElement('input');
        input.className = 'form-control input-predictive predictive-search__input';
        input.type = 'text';
        input.autocomplete = 'off';
        input.placeholder = 'Начните вводить метро...';
        input.dataset.homeLocationMetroInput = '';
        input.value = state.metro?.name || '';
        const list = document.createElement('div');
        list.className = 'address-suggest__list predictive-search__list d-none home-event-location__metro-results';
        list.dataset.homeLocationMetroList = '';
        list.setAttribute('role', 'listbox');
        inputWrap.append(input, list);
        wrap.append(inputWrap);
        card.append(wrap);

        const renderMatches = () => {
            const query = normalizedSearch(input.value);
            if (query.length < 2) {
                list.classList.add('d-none');
                list.replaceChildren();
                return;
            }
            const matches = (state.options?.metro_stations || [])
                .filter((station) => normalizedSearch(`${station.name} ${station.line_name || ''}`).includes(query))
                .slice(0, 8);
            list.replaceChildren();
            matches.forEach((station) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'address-suggest__item predictive-search__item home-event-location__metro-result';
                const dot = document.createElement('span');
                dot.className = 'home-event-location__metro-dot';
                if (station.line_color) dot.style.backgroundColor = station.line_color;
                const name = document.createElement('strong');
                name.textContent = station.name;
                const meta = document.createElement('span');
                meta.textContent = station.line_name || '';
                button.append(dot, name, meta);
                button.addEventListener('click', () => {
                    state.metro = station;
                    state.anchor = {
                        latitude: Number(station.latitude),
                        longitude: Number(station.longitude),
                        label: `м. ${station.name}`,
                        source: 'metro',
                    };
                    state.radiusKm = DEFAULT_RADIUS_KM;
                    resetStreetAndVenue();
                    rebuildMoscowPath();
                    state.index = Math.max(0, state.path.indexOf('metro'));
                    render();
                });
                list.append(button);
            });
            list.classList.toggle('d-none', matches.length === 0);
        };

        input.addEventListener('input', () => {
            if (state.metro && normalizedSearch(input.value) !== normalizedSearch(state.metro.name)) {
                state.metro = null;
                state.anchor = null;
                resetStreetAndVenue();
                rebuildMoscowPath();
            }
            renderMatches();
            syncFooterSoon();
        });
        input.addEventListener('focus', renderMatches);
    }

    function renderRadiusStep(card) {
        stepHead(card, state.source === 'current' ? 'Текущая локация' : (state.anchor?.label || 'Точка поиска'), 'Радиус поиска', 'Покажем мероприятия на площадках в пределах выбранного расстояния.');
        const choices = document.createElement('div');
        choices.className = 'home-event-location__radius-options';
        RADIUS_OPTIONS.forEach((radius) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'home-event-location__radius-option';
            button.classList.toggle('is-selected', Number(state.radiusKm) === radius);
            button.textContent = `${radius} км`;
            button.addEventListener('click', () => {
                state.radiusKm = radius;
                resetVenue();
                render();
            });
            choices.append(button);
        });
        card.append(choices);
    }

    function renderFinalStep(card) {
        stepHead(card, 'Финальное уточнение', 'Улица и площадка', 'Оба поля необязательны. Можно оставить выбранные выше ограничения.');

        const street = document.createElement('div');
        street.className = 'home-event-location__street';
        const label = document.createElement('label');
        label.className = 'form-label';
        label.textContent = 'Улица';
        const inputWrap = document.createElement('div');
        inputWrap.className = 'address-suggest__input-wrap predictive-search__input-wrap';
        const input = document.createElement('input');
        input.className = 'form-control input-predictive predictive-search__input';
        input.type = 'text';
        input.autocomplete = 'off';
        input.placeholder = 'Начните вводить улицу...';
        input.dataset.homeLocationStreetInput = '';
        input.value = state.street;
        const clear = document.createElement('button');
        clear.className = 'address-suggest__control predictive-search__control';
        clear.type = 'button';
        clear.setAttribute('aria-label', 'Очистить улицу');
        clear.hidden = !input.value;
        const list = document.createElement('div');
        list.className = 'address-suggest__list predictive-search__list d-none';
        list.dataset.homeLocationStreetList = '';
        list.setAttribute('role', 'listbox');
        inputWrap.append(input, clear, list);
        street.append(label, inputWrap);

        const hint = document.createElement('p');
        hint.className = 'home-event-location__hint';
        hint.textContent = state.cityId
            ? `Подсказки Яндекса ограничены городом ${city()?.name || ''}.`
            : 'Подсказки Яндекса можно использовать без выбора города.';
        street.append(hint);

        const venueSlot = document.createElement('div');
        venueSlot.className = 'home-event-location__venue';
        venueSlot.dataset.homeLocationVenueSlot = '';
        venueSlot.append(venueSelector);
        card.append(street, venueSlot);

        const showStreetSuggestions = async () => {
            const query = normalize(input.value);
            if (query.length < 2) {
                list.classList.add('d-none');
                list.replaceChildren();
                return;
            }
            const options = await loadOptions();
            if (!options) return;
            const request = ++streetRequest;
            const selectedCity = city();
            const prefixed = selectedCity && !state.cityAny ? `${selectedCity.name}, ${query}` : query;
            const url = new URL(options.address_suggest_url || '/integrations/address-suggest', window.location.origin);
            url.searchParams.set('query', prefixed);
            try {
                const response = await fetch(url, { headers: { Accept: 'application/json' } });
                const payload = await response.json().catch(() => ({}));
                if (!response.ok || request !== streetRequest) return;
                const unique = new Map();
                (payload.suggestions || []).forEach((suggestion) => {
                    const streetName = normalize(suggestion.street);
                    if (!streetName) return;
                    if (state.cityId && suggestion.city_id && Number(suggestion.city_id) !== Number(state.cityId)) return;
                    unique.set(streetName.toLocaleLowerCase('ru'), { ...suggestion, street: streetName });
                });
                const suggestions = [...unique.values()].slice(0, 8);
                list.replaceChildren();
                suggestions.forEach((suggestion) => {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'address-suggest__item predictive-search__item';
                    const text = document.createElement('span');
                    text.className = 'address-suggest__label';
                    text.textContent = suggestion.street;
                    button.append(text);
                    button.addEventListener('click', () => {
                        state.street = suggestion.street;
                        state.streetSuggestion = suggestion;
                        input.value = state.street;
                        clear.hidden = false;
                        list.classList.add('d-none');
                        resetVenue();
                        syncAll();
                        primeVenueSearch();
                    });
                    list.append(button);
                });
                list.classList.toggle('d-none', suggestions.length === 0);
            } catch (_) {
                if (request === streetRequest) {
                    list.classList.add('d-none');
                    list.replaceChildren();
                }
            }
        };

        input.addEventListener('input', () => {
            window.clearTimeout(streetTimer);
            streetRequest += 1;
            const value = normalize(input.value);
            if (value !== state.street) {
                state.street = '';
                state.streetSuggestion = null;
                resetVenue();
                syncAll();
            }
            clear.hidden = value === '';
            streetTimer = window.setTimeout(showStreetSuggestions, 300);
        });
        clear.addEventListener('click', () => {
            input.value = '';
            state.street = '';
            state.streetSuggestion = null;
            clear.hidden = true;
            list.classList.add('d-none');
            resetVenue();
            syncAll();
            input.focus();
        });
    }

    function renderCompletion() {
        const complete = document.createElement('div');
        complete.className = 'home-event-location__mini-complete';
        const icon = document.createElement('i');
        icon.className = 'ti ti-check';
        const body = document.createElement('span');
        const strong = document.createElement('strong');
        const small = document.createElement('small');
        strong.textContent = 'Локация настроена';
        small.textContent = locationLabel();
        body.append(strong, small);
        const edit = document.createElement('button');
        edit.type = 'button';
        edit.className = 'home-text-link';
        edit.textContent = 'Изменить';
        edit.addEventListener('click', () => {
            state.miniActive = true;
            state.index = Math.max(0, state.path.length - 1);
            render();
            focusCurrent();
        });
        complete.append(icon, body, edit);
        return complete;
    }

    function render() {
        updateModeButtons();
        stack.replaceChildren();
        mini.classList.toggle('is-active', state.mode === 'specific' && state.miniActive);

        if (state.mode !== 'specific') {
            syncAll();
            return;
        }

        if (state.source === 'current') {
            const source = document.createElement('div');
            source.className = 'home-event-location__source-summary';
            source.innerHTML = '<i class="ti ti-current-location"></i><span><small>Точка поиска</small><strong>Текущая локация</strong></span>';
            stack.append(source);
        }

        const completedUntil = state.miniActive ? state.index : state.path.length;
        for (let index = 0; index < completedUntil; index += 1) {
            const step = state.path[index];
            if (step === 'final' && state.miniActive) continue;
            stack.append(createSummary(step, index));
        }

        if (state.miniActive) {
            stack.append(activeCard());
        } else if (state.path.length > 0) {
            stack.append(renderCompletion());
        }

        syncAll();
    }

    function syncAll() {
        syncFilterDataset();
        syncOuterValue();
        syncFooterSoon();
    }

    function showStatus(message, kind = 'info') {
        status.textContent = message;
        status.dataset.state = kind;
        status.hidden = false;
    }

    function hideStatus() {
        status.textContent = '';
        status.hidden = true;
        delete status.dataset.state;
    }

    async function loadOptions() {
        if (state.loaded || state.loading) return state.options;
        state.loading = true;
        showStatus('Загружаем справочники…', 'loading');
        try {
            const response = await fetch(LOCATION_OPTIONS_URL, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok || !Array.isArray(payload.cities)) {
                throw new Error(payload.message || 'Не удалось загрузить географию.');
            }
            state.options = payload;
            state.loaded = true;
            hideStatus();
            return payload;
        } catch (error) {
            showStatus(error.message || 'Не удалось загрузить географию.', 'error');
            return null;
        } finally {
            state.loading = false;
        }
    }

    async function chooseSpecific() {
        state.mode = 'specific';
        state.source = 'manual';
        const options = await loadOptions();
        if (!options) {
            updateModeButtons();
            return;
        }
        clearManualGeography();
        state.path = ['city'];
        state.index = 0;
        state.miniActive = true;
        hideStatus();
        render();
        focusCurrent();
    }

    async function useCurrentLocation() {
        const options = await loadOptions();
        if (!options) return;

        geolocationButton.disabled = true;
        const label = geolocationButton.querySelector('span');
        const previousText = label?.textContent || 'Текущая локация';
        if (label) label.textContent = 'Определяем…';
        showStatus('Определяем местоположение…', 'loading');

        try {
            const coordinates = await getCurrentCoordinates();
            let suggestion = null;
            try {
                suggestion = await reverseGeocode(
                    options.address_reverse_url || '/integrations/address-reverse',
                    coordinates.latitude,
                    coordinates.longitude,
                );
            } catch (_) {
                suggestion = null;
            }

            state.mode = 'specific';
            state.source = 'current';
            clearManualGeography();
            state.anchor = {
                latitude: Number(suggestion?.latitude ?? coordinates.latitude),
                longitude: Number(suggestion?.longitude ?? coordinates.longitude),
                label: 'Текущая локация',
                source: 'current',
            };
            state.radiusKm = DEFAULT_RADIUS_KM;
            state.path = ['radius', 'final'];
            state.index = 0;
            state.miniActive = true;
            hideStatus();
            render();
        } catch (_) {
            showStatus(GEOLOCATION_ERROR, 'error');
        } finally {
            geolocationButton.disabled = false;
            if (label) label.textContent = previousText;
        }
    }

    function advanceMini() {
        const step = currentStep();
        if (!step || !stepComplete(step)) return;

        if (step === 'city') {
            state.path = manualPathAfterCity();
        } else if (step === 'moscow-mode') {
            rebuildMoscowPath();
        } else if (step === 'metro') {
            rebuildMoscowPath();
        }

        if (state.index >= state.path.length - 1 || step === 'final') {
            state.miniActive = false;
            render();
            return;
        }

        state.index += 1;
        render();
        focusCurrent();
    }

    function skipMini() {
        const step = currentStep();
        if (!step || !canSkip(step)) return;

        if (step === 'city') {
            state.cityId = null;
            state.cityAny = true;
            resetAfterCity();
            state.path = ['city', 'final'];
        } else if (step === 'moscow-mode') {
            resetAfterMoscowMode();
            state.moscowMode = 'any';
            state.path = ['city', 'moscow-mode', 'final'];
        } else if (step === 'district') {
            state.districtId = null;
            state.districtAny = true;
            resetStreetAndVenue();
        } else if (step === 'metro') {
            resetAfterMoscowMode();
            state.moscowMode = 'any';
            state.path = ['city', 'moscow-mode', 'final'];
            state.index = 1;
        } else if (step === 'final') {
            state.miniActive = false;
            render();
            return;
        }

        if (state.index >= state.path.length - 1) {
            state.miniActive = false;
        } else {
            state.index += 1;
        }
        render();
        focusCurrent();
    }

    function backMini() {
        if (state.index > 0) {
            state.index -= 1;
            render();
            focusCurrent();
            return;
        }

        state.mode = null;
        state.miniActive = false;
        state.path = [];
        clearManualGeography();
        syncOuterValue();
        render();
    }

    function focusCurrent() {
        window.setTimeout(() => {
            const card = stack.querySelector('.home-event-location__mini-active-card');
            card?.querySelector('select, input, button')?.focus();
        }, 0);
    }

    function footerElements() {
        const footer = panel.querySelector('[data-home-flow-wizard-nav]');
        return {
            footer,
            back: footer?.querySelector('[data-home-flow-wizard-back]'),
            skip: footer?.querySelector('[data-home-flow-wizard-skip]'),
            next: footer?.querySelector('[data-home-flow-wizard-next]'),
        };
    }

    function syncFooter() {
        if (!visibleLocationStep.classList.contains('is-active')) return;
        const { footer, back, skip, next } = footerElements();
        if (!footer || !back || !next) return;

        if (state.mode === 'specific' && state.miniActive) {
            footer.dataset.homeLocationMiniActive = '1';
            back.disabled = false;
            back.setAttribute('aria-disabled', 'false');
            if (skip) skip.hidden = !canSkip(currentStep());
            next.disabled = !stepComplete(currentStep());
            next.setAttribute('aria-disabled', String(next.disabled));
            const nextLabel = next.querySelector('span');
            const nextIcon = next.querySelector('i');
            if (nextLabel) nextLabel.textContent = currentStep() === 'final' ? 'Готово' : 'Далее';
            if (nextIcon) nextIcon.className = currentStep() === 'final' ? 'ti ti-check' : 'ti ti-arrow-right';
            return;
        }

        delete footer.dataset.homeLocationMiniActive;
        if (skip) skip.hidden = true;
        const nextLabel = next.querySelector('span');
        const nextIcon = next.querySelector('i');
        if (nextLabel) nextLabel.textContent = 'Далее';
        if (nextIcon) nextIcon.className = 'ti ti-arrow-right';
        const selected = state.mode === 'any' || (state.mode === 'specific' && !state.miniActive && state.path.length > 0);
        next.disabled = !selected;
        next.setAttribute('aria-disabled', String(!selected));
    }

    function syncFooterSoon() {
        window.setTimeout(syncFooter, 0);
        window.setTimeout(syncFooter, 30);
    }

    function primeVenueSearch() {
        if (!state.street || state.venueId) return;
        const visible = venueInput.value;
        venueInput.value = state.street;
        venueInput.dispatchEvent(new Event('input', { bubbles: true }));
        venueInput.value = visible === state.street ? '' : visible;
        syncOuterValue();
    }

    function reset(mode = 'any') {
        state.mode = mode;
        state.miniActive = false;
        state.path = [];
        state.index = 0;
        state.source = 'manual';
        clearManualGeography();
        state.anchor = null;
        state.radiusKm = DEFAULT_RADIUS_KM;
        hideStatus();
        venueSelector.dataset.searchUrl = baseVenueSearchUrl;
        delete venueSelector.dataset.locationCityFilter;
        delete venueSelector.dataset.locationStreetFilter;
        if (/^\d+$/.test(String(venueValue.value || ''))) {
            venueClear?.click();
        }
        render();
    }

    modeGrid.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-home-event-location-mode]');
        if (!button) return;
        if (button.dataset.homeEventLocationMode === 'any') {
            reset('any');
        } else {
            await chooseSpecific();
        }
    });

    geolocationButton.addEventListener('click', useCurrentLocation);

    venueValue.addEventListener('change', () => {
        if (suppressVenueChange) return;
        const raw = String(venueValue.value || '');
        if (/^\d+$/.test(raw)) {
            state.venueId = Number(raw);
            state.venueLabel = normalize(venueInput.value) || 'Выбранная площадка';
            if (state.miniActive && !visibleLocationStep.classList.contains('is-active')) {
                window.setTimeout(() => visibleLocationStep.click(), 0);
            }
        } else if (![LOCATION_SENTINEL_ANY, LOCATION_SENTINEL_FILTERS].includes(raw)) {
            state.venueId = null;
            state.venueLabel = '';
        }
        syncFooterSoon();
    });

    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-home-flow-wizard-back], [data-home-flow-wizard-skip], [data-home-flow-wizard-next]');
        if (!button || !panel.contains(button) || !visibleLocationStep.classList.contains('is-active')) return;

        if (state.mode === 'specific' && state.miniActive) {
            event.preventDefault();
            event.stopImmediatePropagation();
            if (button.matches('[data-home-flow-wizard-back]')) backMini();
            else if (button.matches('[data-home-flow-wizard-skip]')) skipMini();
            else advanceMini();
            return;
        }

        if (button.matches('[data-home-flow-wizard-next]')
            && state.mode === 'specific'
            && !state.miniActive
            && !/^\d+$/.test(String(venueValue.value || ''))) {
            const visible = venueInput.value;
            venueInput.value = locationLabel();
            window.setTimeout(() => {
                if (!/^\d+$/.test(String(venueValue.value || ''))) {
                    venueInput.value = visible;
                }
            }, 0);
        }
    }, { capture: true });

    const stepObserver = new MutationObserver(() => {
        if (!visibleLocationStep.classList.contains('is-active')) return;
        const copy = panel.querySelector('.home-flow-step-copy');
        const title = copy?.querySelector('strong');
        const description = copy?.querySelector('span');
        if (title) title.textContent = 'Где искать мероприятие?';
        if (description) description.textContent = 'Выберите способ поиска. Ненужные географические шаги будут пропущены автоматически.';
        syncOuterValue();
        syncFooterSoon();
    });
    stepObserver.observe(visibleLocationStep, { attributes: true, attributeFilter: ['class'] });

    flow.addEventListener('click', (event) => {
        if (event.target.closest('[data-home-flow-type]')) {
            window.setTimeout(() => reset('any'), 0);
        }
    });

    $(document).on('modal:opened.homeEventLocationV2', function (_event, modal) {
        if (modal.find('[data-home-flow="event"]').get(0) === flow) {
            window.setTimeout(() => {
                reset('any');
                syncFooterSoon();
            }, 0);
        }
    });

    $(document).on('click.homeEventLocationV2', '[data-home-flow="event"] [data-home-flow-tab="search"]', function () {
        window.setTimeout(() => reset('any'), 0);
    });

    reset('any');
    return { reset, state };
}

const eventFlow = document.querySelector('[data-home-flow="event"]');
initHomeEventLocation(eventFlow);
