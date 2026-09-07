import $ from 'jquery';
import { getCurrentCoordinates, reverseGeocode } from './current-location.js';
import '../../css/pages/home-event-location.css';

const LOCATION_OPTIONS_URL = '/home/location-options';
const LOCATION_SENTINEL_ANY = '__home_location_any__';
const LOCATION_SENTINEL_FILTERS = '__home_location_filters__';
const GEOLOCATION_ERROR = 'Не удалось получить геопозицию. Попробуйте ещё раз или введите вручную ниже';

function normalize(value) {
    return String(value || '').replace(/\s+/g, ' ').trim();
}

function metroLabel(option) {
    if (!option) {
        return '';
    }

    try {
        const data = JSON.parse(option.dataset.data || '{}');
        if (data.text) {
            return normalize(data.text);
        }
    } catch (_) {
        // Fall back to the visible option text.
    }

    return normalize(option.textContent).replace(/\s*\([^)]*\)\s*$/, '').trim();
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
    const venueList = venueSelector?.querySelector('[data-venue-selector-list]');
    const metroSelect = venueSelector?.querySelector('[data-venue-selector-metro-filter]');
    const metroToggle = venueSelector?.querySelector('[data-venue-selector-metro-toggle]');
    const metroPanel = venueSelector?.querySelector('[data-venue-selector-metro-panel]');
    const visibleLocationStep = panel?.querySelector('[data-home-event-search-step="2"]');

    if (!panel || !locationStage || !venueSelector || !venueInput || !venueValue || !visibleLocationStep) {
        return null;
    }

    const root = document.createElement('div');
    root.className = 'home-event-location';

    const modeGrid = document.createElement('div');
    modeGrid.className = 'home-event-location__modes';
    modeGrid.innerHTML = `
        <button type="button" class="home-event-location__mode" data-home-event-location-mode="any" aria-pressed="true">
            <i class="ti ti-adjustments-off"></i><span><strong>Не важно</strong><small>Искать без ограничения по локации</small></span>
        </button>
        <button type="button" class="home-event-location__mode" data-home-event-location-mode="specific" aria-pressed="false">
            <i class="ti ti-map-pin"></i><span><strong>Указать локацию</strong><small>Город, район, метро, улица или площадка</small></span>
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

    const badges = document.createElement('div');
    badges.className = 'home-event-location__badges';
    badges.hidden = true;

    const mini = document.createElement('div');
    mini.className = 'home-event-location__mini';

    const miniProgress = document.createElement('div');
    miniProgress.className = 'home-event-location__mini-progress';
    miniProgress.innerHTML = `
        <button type="button" data-home-location-mini-step="0"><span>1</span><strong>Город</strong></button>
        <button type="button" data-home-location-mini-step="1"><span>2</span><strong>Район / округ</strong></button>
        <button type="button" data-home-location-mini-step="2"><span>3</span><strong>Метро</strong></button>
        <button type="button" data-home-location-mini-step="3"><span>4</span><strong>Улица и площадка</strong></button>
    `;

    const miniContent = document.createElement('div');
    miniContent.className = 'home-event-location__mini-content';

    const cityStep = document.createElement('section');
    cityStep.className = 'home-event-location__substep';
    cityStep.dataset.homeLocationSubstep = '0';
    cityStep.innerHTML = `
        <div class="home-event-location__substep-head"><small>Шаг 1</small><strong>Выберите город</strong></div>
        <label class="home-event-location__select-wrap">
            <span>Город</span>
            <select class="form-select" data-home-location-city>
                <option value="">Выбрать город</option>
            </select>
        </label>
        <button type="button" class="home-text-link home-event-location__any" data-home-location-city-any>Не важно</button>
    `;

    const districtStep = document.createElement('section');
    districtStep.className = 'home-event-location__substep';
    districtStep.dataset.homeLocationSubstep = '1';
    districtStep.hidden = true;
    districtStep.innerHTML = `
        <div class="home-event-location__substep-head"><small>Шаг 2</small><strong>Выберите район или округ</strong></div>
        <label class="home-event-location__select-wrap">
            <span>Район / округ</span>
            <select class="form-select" data-home-location-district>
                <option value="">Выбрать район</option>
            </select>
        </label>
        <button type="button" class="home-text-link home-event-location__any" data-home-location-district-any>Не важно</button>
    `;

    const metroStep = document.createElement('section');
    metroStep.className = 'home-event-location__substep';
    metroStep.dataset.homeLocationSubstep = '2';
    metroStep.hidden = true;
    metroStep.innerHTML = `
        <div class="home-event-location__substep-head"><small>Шаг 3</small><strong>Выберите метро</strong></div>
        <div class="home-event-location__metro-slot" data-home-location-metro-slot></div>
        <button type="button" class="home-text-link home-event-location__any" data-home-location-metro-any>Не важно</button>
    `;

    const finalStep = document.createElement('section');
    finalStep.className = 'home-event-location__substep';
    finalStep.dataset.homeLocationSubstep = '3';
    finalStep.hidden = true;
    finalStep.innerHTML = `
        <div class="home-event-location__substep-head"><small>Шаг 4</small><strong>Уточните улицу или площадку</strong><span>Оба поля необязательны — можно использовать только выбранные выше фильтры.</span></div>
        <div class="home-event-location__street">
            <label class="form-label" for="homeEventStreetSearch">Улица</label>
            <div class="address-suggest__input-wrap predictive-search__input-wrap">
                <input id="homeEventStreetSearch" class="form-control input-predictive predictive-search__input" type="text" autocomplete="off" placeholder="Начните вводить улицу..." data-home-location-street-input>
                <button class="address-suggest__control predictive-search__control" type="button" aria-label="Очистить улицу" data-home-location-street-clear hidden></button>
                <div class="address-suggest__list predictive-search__list d-none" role="listbox" data-home-location-street-list></div>
            </div>
            <p class="home-event-location__hint" data-home-location-street-hint>Подсказки Яндекса будут ограничены выбранным городом.</p>
        </div>
        <div class="home-event-location__venue" data-home-location-venue-slot></div>
    `;

    miniContent.append(cityStep, districtStep, metroStep, finalStep);
    mini.append(miniProgress, miniContent);
    specific.append(geolocationRow, badges, mini);
    root.append(modeGrid, specific);

    const venueSlot = finalStep.querySelector('[data-home-location-venue-slot]');
    venueSlot.append(venueSelector);
    locationStage.replaceChildren(root);

    const metroSlot = metroStep.querySelector('[data-home-location-metro-slot]');
    if (metroSelect) {
        metroSlot.append(metroSelect);
        metroSelect.dataset.placeholder = 'Выберите метро';
    } else {
        metroSlot.innerHTML = '<p class="home-event-location__hint">Справочник метро недоступен.</p>';
    }
    if (metroToggle) {
        metroToggle.hidden = true;
    }
    if (metroPanel) {
        metroPanel.hidden = true;
    }

    venueSelector.querySelector('.venue-selector__links')?.classList.add('home-event-location__venue-links');

    const modeButtons = [...modeGrid.querySelectorAll('[data-home-event-location-mode]')];
    const progressButtons = [...miniProgress.querySelectorAll('[data-home-location-mini-step]')];
    const substeps = [...miniContent.querySelectorAll('[data-home-location-substep]')];
    const citySelect = cityStep.querySelector('[data-home-location-city]');
    const cityAny = cityStep.querySelector('[data-home-location-city-any]');
    const districtSelect = districtStep.querySelector('[data-home-location-district]');
    const districtAny = districtStep.querySelector('[data-home-location-district-any]');
    const metroAny = metroStep.querySelector('[data-home-location-metro-any]');
    const streetInput = finalStep.querySelector('[data-home-location-street-input]');
    const streetClear = finalStep.querySelector('[data-home-location-street-clear]');
    const streetList = finalStep.querySelector('[data-home-location-street-list]');
    const streetHint = finalStep.querySelector('[data-home-location-street-hint]');
    const geolocationButton = geolocationRow.querySelector('[data-home-event-current-location]');
    const status = geolocationRow.querySelector('[data-home-event-location-status]');
    const footerNext = panel.querySelector('[data-home-flow-wizard-next]');

    const state = {
        mode: 'any',
        substep: 0,
        loaded: false,
        loading: false,
        options: null,
        cityId: null,
        cityAny: false,
        districtId: null,
        districtAny: false,
        metroIds: [],
        metroLabels: [],
        metroAny: false,
        street: '',
        streetSuggestion: null,
        venueId: null,
        venueLabel: '',
        latitude: null,
        longitude: null,
    };

    let streetTimer = null;
    let streetRequest = 0;
    let suppressMetro = false;

    function city() {
        return state.options?.cities?.find((item) => Number(item.id) === Number(state.cityId)) || null;
    }

    function district() {
        return city()?.districts?.find((item) => Number(item.id) === Number(state.districtId)) || null;
    }

    function cityDone() {
        return state.cityAny || Boolean(state.cityId);
    }

    function districtDone() {
        return state.districtAny || Boolean(state.districtId);
    }

    function metroDone() {
        return state.metroAny || state.metroIds.length > 0 || !metroSelect;
    }

    function isComplete() {
        return state.mode === 'any'
            || (state.mode === 'specific' && state.substep >= 3 && cityDone() && districtDone() && metroDone());
    }

    function locationLabel() {
        if (state.mode === 'any') {
            return 'Не важно';
        }

        if (state.venueId && state.venueLabel) {
            return state.venueLabel;
        }

        const parts = [];
        if (state.cityId) {
            parts.push(city()?.name || 'Город');
        }
        if (state.districtId) {
            const item = district();
            parts.push(item?.short_name || item?.name || 'Район');
        }
        if (state.metroIds.length > 0) {
            parts.push(`м. ${state.metroLabels.join(', ')}`);
        }
        if (state.street) {
            parts.push(state.street);
        }

        return parts.length ? parts.join(' · ') : 'Локация указана';
    }

    function setOuterValue(value, label, dispatch = true) {
        const visible = venueInput.value;
        venueValue.value = value;
        venueInput.value = label;
        if (dispatch) {
            venueValue.dispatchEvent(new Event('change', { bubbles: true }));
        }
        venueInput.value = visible;
    }

    function syncOuterState() {
        if (!isComplete()) {
            if ([LOCATION_SENTINEL_ANY, LOCATION_SENTINEL_FILTERS].includes(venueValue.value)) {
                venueValue.value = '';
                venueValue.dispatchEvent(new Event('change', { bubbles: true }));
            }
            return;
        }

        if (state.venueId && /^\d+$/.test(String(venueValue.value))) {
            return;
        }

        setOuterValue(
            state.mode === 'any' ? LOCATION_SENTINEL_ANY : LOCATION_SENTINEL_FILTERS,
            locationLabel(),
        );
    }

    function updateModeButtons() {
        modeButtons.forEach((button) => {
            const selected = button.dataset.homeEventLocationMode === state.mode;
            button.classList.toggle('is-selected', selected);
            button.setAttribute('aria-pressed', String(selected));
        });
        specific.hidden = state.mode !== 'specific';
    }

    function updateMiniProgress() {
        progressButtons.forEach((button, index) => {
            const active = index === state.substep;
            const complete = index < state.substep
                || (index === 0 && cityDone())
                || (index === 1 && districtDone())
                || (index === 2 && metroDone())
                || (index === 3 && isComplete());
            button.classList.toggle('is-active', active);
            button.classList.toggle('is-complete', complete);
            button.disabled = index > state.substep;
        });

        substeps.forEach((section, index) => {
            section.hidden = index !== state.substep;
        });
    }

    function addBadge(label, value, step) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'home-event-location__badge';
        button.innerHTML = `<small>${label}</small><strong>${value}</strong><i class="ti ti-pencil"></i>`;
        button.addEventListener('click', () => {
            state.substep = step;
            updateMiniProgress();
            window.setTimeout(() => focusCurrentSubstep(), 0);
        });
        badges.append(button);
    }

    function renderBadges() {
        badges.replaceChildren();

        if (state.mode !== 'specific') {
            badges.hidden = true;
            return;
        }

        if (cityDone()) {
            addBadge('Город', state.cityAny ? 'Не важно' : (city()?.name || 'Выбран'), 0);
        }
        if (districtDone()) {
            const item = district();
            addBadge('Район / округ', state.districtAny ? 'Не важно' : (item?.short_name || item?.name || 'Выбран'), 1);
        }
        if (metroDone()) {
            addBadge('Метро', state.metroAny || !metroSelect ? 'Не важно' : state.metroLabels.join(', '), 2);
        }
        if (state.street) {
            addBadge('Улица', state.street, 3);
        }
        if (state.venueId) {
            addBadge('Площадка', state.venueLabel || 'Выбрана', 3);
        }

        badges.hidden = badges.childElementCount === 0;
    }

    function updateVenueLocationFilters() {
        const selectedCity = city();
        if (selectedCity && !state.cityAny) {
            venueSelector.dataset.locationCityFilter = selectedCity.name;
        } else {
            delete venueSelector.dataset.locationCityFilter;
        }

        if (state.street) {
            venueSelector.dataset.locationStreetFilter = state.street;
        } else {
            delete venueSelector.dataset.locationStreetFilter;
        }
    }

    function normalizedSearchText(value) {
        return normalize(value).toLocaleLowerCase('ru');
    }

    function venueMatchesLocation(venue) {
        const address = normalizedSearchText(venue?.address || venue?.raw_address || '');
        const selectedCity = city();
        const cityMatch = !selectedCity || state.cityAny
            || address.includes(normalizedSearchText(selectedCity.name));
        const streetMatch = !state.street
            || address.includes(normalizedSearchText(state.street));
        return cityMatch && streetMatch;
    }

    function filterVenueSuggestions() {
        if (!venueList || (!state.street && (!state.cityId || state.cityAny))) {
            return;
        }

        let venues = [];
        try {
            venues = JSON.parse(venueList.dataset.venues || '[]');
        } catch (_) {
            venues = [];
        }

        let visible = 0;
        venueList.querySelectorAll('[data-venue-selector-option]').forEach((option) => {
            const venue = venues[Number(option.dataset.venueSelectorOption)];
            const matches = venue ? venueMatchesLocation(venue) : true;
            option.hidden = !matches;
            if (matches) {
                visible += 1;
            }
        });

        if (venues.length > 0) {
            venueList.classList.toggle('d-none', visible === 0);
        }
    }

    function primeVenueSearchFromStreet() {
        if (!state.street || state.venueId) {
            return;
        }

        const visible = venueInput.value;
        venueInput.value = state.street;
        venueInput.dispatchEvent(new Event('input', { bubbles: true }));
        venueInput.value = visible === state.street ? '' : visible;
        window.setTimeout(() => {
            syncOuterState();
            filterVenueSuggestions();
        }, 0);
    }

    function resetVenue() {
        state.venueId = null;
        state.venueLabel = '';
        if (/^\d+$/.test(String(venueValue.value))) {
            venueClear?.click();
        }
        updateVenueLocationFilters();
    }

    function clearMetroSelection() {
        suppressMetro = true;
        if (metroSelect?.tomselect) {
            metroSelect.tomselect.clear(true);
        } else if (metroSelect) {
            Array.from(metroSelect.options).forEach((option) => {
                option.selected = false;
            });
            metroSelect.dispatchEvent(new Event('change', { bubbles: true }));
        }
        suppressMetro = false;
        state.metroIds = [];
        state.metroLabels = [];
    }

    function resetAfterCity() {
        state.districtId = null;
        state.districtAny = false;
        state.metroAny = false;
        clearMetroSelection();
        state.street = '';
        state.streetSuggestion = null;
        streetInput.value = '';
        streetClear.hidden = true;
        state.latitude = null;
        state.longitude = null;
        resetVenue();
    }

    function resetAfterDistrict() {
        state.metroAny = false;
        clearMetroSelection();
        state.street = '';
        state.streetSuggestion = null;
        streetInput.value = '';
        streetClear.hidden = true;
        resetVenue();
    }

    function populateDistricts() {
        districtSelect.replaceChildren(new Option('Выбрать район', ''));
        const districts = city()?.districts || [];
        districts.forEach((item) => {
            const label = item.short_name ? `${item.short_name} — ${item.name}` : item.name;
            districtSelect.add(new Option(label, String(item.id)));
        });

        if (state.districtId) {
            districtSelect.value = String(state.districtId);
        }

        return districts;
    }

    async function loadOptions() {
        if (state.loaded || state.loading) {
            return state.options;
        }

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
            citySelect.replaceChildren(new Option('Выбрать город', ''));
            payload.cities.forEach((item) => citySelect.add(new Option(item.name, String(item.id))));
            hideStatus();
            return payload;
        } catch (error) {
            showStatus(error.message || 'Не удалось загрузить географию.', 'error');
            return null;
        } finally {
            state.loading = false;
        }
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

    function setSubstep(index) {
        state.substep = Math.max(0, Math.min(3, index));
        updateMiniProgress();
        renderBadges();
        updateVenueLocationFilters();
        syncOuterState();
        window.setTimeout(() => focusCurrentSubstep(), 0);
    }

    function focusCurrentSubstep() {
        if (state.substep === 0) {
            citySelect.focus();
        } else if (state.substep === 1) {
            districtSelect.focus();
        } else if (state.substep === 2) {
            metroSelect?.tomselect?.focus?.();
        } else if (state.substep === 3) {
            streetInput.focus();
        }
    }

    function reset(mode = 'any') {
        state.mode = mode;
        state.substep = 0;
        state.cityId = null;
        state.cityAny = false;
        state.districtId = null;
        state.districtAny = false;
        state.metroAny = false;
        clearMetroSelection();
        state.street = '';
        state.streetSuggestion = null;
        state.venueId = null;
        state.venueLabel = '';
        state.latitude = null;
        state.longitude = null;
        streetInput.value = '';
        streetClear.hidden = true;
        districtSelect.replaceChildren(new Option('Выбрать район', ''));
        citySelect.value = '';
        delete venueSelector.dataset.locationCityFilter;
        delete venueSelector.dataset.locationStreetFilter;
        if (venueValue.value || venueInput.value) {
            venueClear?.click();
        }
        updateModeButtons();
        updateMiniProgress();
        renderBadges();
        hideStatus();
        syncOuterState();
    }

    async function chooseSpecific() {
        state.mode = 'specific';
        updateModeButtons();
        const options = await loadOptions();
        if (!options) {
            return;
        }
        setSubstep(0);
    }

    function selectedMetroValues() {
        const options = Array.from(metroSelect?.selectedOptions || []);
        return {
            ids: options.map((option) => Number(option.value)).filter((value) => Number.isInteger(value) && value > 0),
            labels: options.map(metroLabel).filter(Boolean),
        };
    }

    function applyMetroIds(ids) {
        const normalizedIds = (ids || []).map(String);
        suppressMetro = true;
        if (metroSelect?.tomselect) {
            metroSelect.tomselect.clear(true);
            normalizedIds.forEach((id) => metroSelect.tomselect.addItem(id, true));
            metroSelect.tomselect.refreshItems();
        } else if (metroSelect) {
            Array.from(metroSelect.options).forEach((option) => {
                option.selected = normalizedIds.includes(option.value);
            });
        }
        suppressMetro = false;
        const selected = selectedMetroValues();
        state.metroIds = selected.ids;
        state.metroLabels = selected.labels;
        state.metroAny = selected.ids.length === 0;
    }

    async function useCurrentLocation() {
        const options = await loadOptions();
        if (!options) {
            return;
        }

        geolocationButton.disabled = true;
        const label = geolocationButton.querySelector('span');
        const previousText = label?.textContent || 'Текущая локация';
        if (label) {
            label.textContent = 'Определяем…';
        }
        showStatus('Определяем местоположение…', 'loading');

        try {
            const coordinates = await getCurrentCoordinates();
            const suggestion = await reverseGeocode(
                options.address_reverse_url || '/integrations/address-reverse',
                coordinates.latitude,
                coordinates.longitude,
            );
            if (!suggestion) {
                throw new Error('reverse_failed');
            }

            state.mode = 'specific';
            state.latitude = Number(suggestion.latitude || coordinates.latitude) || coordinates.latitude;
            state.longitude = Number(suggestion.longitude || coordinates.longitude) || coordinates.longitude;
            state.cityId = options.cities.some((item) => Number(item.id) === Number(suggestion.city_id))
                ? Number(suggestion.city_id)
                : null;
            state.cityAny = !state.cityId;
            citySelect.value = state.cityId ? String(state.cityId) : '';

            populateDistricts();
            const currentCity = city();
            state.districtId = currentCity?.districts?.some((item) => Number(item.id) === Number(suggestion.district_id))
                ? Number(suggestion.district_id)
                : null;
            state.districtAny = !state.districtId;
            districtSelect.value = state.districtId ? String(state.districtId) : '';

            applyMetroIds(suggestion.metro_station_ids || []);
            state.street = normalize(suggestion.street);
            state.streetSuggestion = state.street ? suggestion : null;
            streetInput.value = state.street;
            streetClear.hidden = !state.street;
            resetVenue();
            state.substep = 3;

            updateModeButtons();
            updateMiniProgress();
            renderBadges();
            updateVenueLocationFilters();
            syncOuterState();
            primeVenueSearchFromStreet();
            hideStatus();
        } catch (_) {
            showStatus(GEOLOCATION_ERROR, 'error');
        } finally {
            geolocationButton.disabled = false;
            if (label) {
                label.textContent = previousText;
            }
        }
    }

    async function loadStreetSuggestions(query) {
        const options = await loadOptions();
        if (!options || query.length < 2) {
            streetList.classList.add('d-none');
            streetList.replaceChildren();
            return;
        }

        const request = ++streetRequest;
        const selectedCity = city();
        const prefixed = selectedCity && !state.cityAny
            ? `${selectedCity.name}, ${query}`
            : query;
        const url = new URL(options.address_suggest_url || '/integrations/address-suggest', window.location.origin);
        url.searchParams.set('query', prefixed);

        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' } });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok || request !== streetRequest) {
                return;
            }

            const unique = new Map();
            (payload.suggestions || []).forEach((suggestion) => {
                const street = normalize(suggestion.street);
                if (!street) {
                    return;
                }
                if (state.cityId && suggestion.city_id && Number(suggestion.city_id) !== Number(state.cityId)) {
                    return;
                }
                unique.set(street.toLocaleLowerCase('ru'), { ...suggestion, street });
            });

            const suggestions = [...unique.values()].slice(0, 8);
            streetList.dataset.homeStreetSuggestions = JSON.stringify(suggestions);
            streetList.replaceChildren();
            suggestions.forEach((suggestion, index) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'address-suggest__item predictive-search__item';
                button.dataset.homeStreetSuggestion = String(index);
                button.innerHTML = `<span class="address-suggest__label">${suggestion.street}</span>`;
                streetList.append(button);
            });
            streetList.classList.toggle('d-none', suggestions.length === 0);
        } catch (_) {
            if (request === streetRequest) {
                streetList.classList.add('d-none');
                streetList.replaceChildren();
            }
        }
    }

    modeGrid.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-home-event-location-mode]');
        if (!button) {
            return;
        }

        if (button.dataset.homeEventLocationMode === 'any') {
            reset('any');
        } else {
            await chooseSpecific();
        }
    });

    citySelect.addEventListener('change', () => {
        const value = Number(citySelect.value);
        if (!value) {
            return;
        }

        state.cityId = value;
        state.cityAny = false;
        resetAfterCity();
        const districts = populateDistricts();
        if (districts.length === 0) {
            state.districtAny = true;
            setSubstep(2);
        } else {
            setSubstep(1);
        }
    });

    cityAny.addEventListener('click', () => {
        state.cityId = null;
        state.cityAny = true;
        resetAfterCity();
        state.districtAny = true;
        districtSelect.replaceChildren(new Option('Выбрать район', ''));
        setSubstep(2);
    });

    districtSelect.addEventListener('change', () => {
        const value = Number(districtSelect.value);
        if (!value) {
            return;
        }
        state.districtId = value;
        state.districtAny = false;
        resetAfterDistrict();
        setSubstep(2);
    });

    districtAny.addEventListener('click', () => {
        state.districtId = null;
        state.districtAny = true;
        resetAfterDistrict();
        setSubstep(2);
    });

    metroSelect?.addEventListener('change', () => {
        if (suppressMetro) {
            return;
        }
        const selected = selectedMetroValues();
        state.metroIds = selected.ids;
        state.metroLabels = selected.labels;
        state.metroAny = false;
        resetVenue();

        if (state.metroIds.length > 0) {
            setSubstep(3);
        } else {
            state.substep = 2;
            updateMiniProgress();
            renderBadges();
            syncOuterState();
        }
    });

    metroAny.addEventListener('click', () => {
        clearMetroSelection();
        state.metroAny = true;
        resetVenue();
        setSubstep(3);
    });

    progressButtons.forEach((button, index) => {
        button.addEventListener('click', () => {
            if (index <= state.substep) {
                setSubstep(index);
            }
        });
    });

    geolocationButton.addEventListener('click', useCurrentLocation);

    streetInput.addEventListener('input', () => {
        window.clearTimeout(streetTimer);
        streetRequest += 1;
        const value = normalize(streetInput.value);
        if (value !== state.street) {
            state.street = '';
            state.streetSuggestion = null;
            resetVenue();
            renderBadges();
            updateVenueLocationFilters();
            syncOuterState();
        }
        streetClear.hidden = value === '';
        streetTimer = window.setTimeout(() => loadStreetSuggestions(value), 300);
    });

    streetClear.addEventListener('click', () => {
        streetInput.value = '';
        state.street = '';
        state.streetSuggestion = null;
        streetClear.hidden = true;
        streetList.classList.add('d-none');
        streetList.replaceChildren();
        resetVenue();
        renderBadges();
        updateVenueLocationFilters();
        syncOuterState();
        streetInput.focus();
    });

    streetList.addEventListener('click', (event) => {
        const button = event.target.closest('[data-home-street-suggestion]');
        if (!button) {
            return;
        }
        const suggestions = JSON.parse(streetList.dataset.homeStreetSuggestions || '[]');
        const suggestion = suggestions[Number(button.dataset.homeStreetSuggestion)];
        if (!suggestion) {
            return;
        }

        state.street = normalize(suggestion.street);
        state.streetSuggestion = suggestion;
        streetInput.value = state.street;
        streetClear.hidden = false;
        streetList.classList.add('d-none');
        resetVenue();
        renderBadges();
        updateVenueLocationFilters();
        syncOuterState();
        primeVenueSearchFromStreet();
    });

    if (venueList) {
        const venueListObserver = new MutationObserver(filterVenueSuggestions);
        venueListObserver.observe(venueList, { childList: true });
    }

    document.addEventListener('click', (event) => {
        if (!finalStep.contains(event.target)) {
            streetList.classList.add('d-none');
        }
    });

    venueValue.addEventListener('change', () => {
        const raw = String(venueValue.value || '');
        if (/^\d+$/.test(raw)) {
            state.venueId = Number(raw);
            state.venueLabel = normalize(venueInput.value) || 'Выбранная площадка';
            renderBadges();
            return;
        }
        if (![LOCATION_SENTINEL_ANY, LOCATION_SENTINEL_FILTERS].includes(raw)) {
            state.venueId = null;
            state.venueLabel = '';
            renderBadges();
            if (isComplete()) {
                window.setTimeout(syncOuterState, 0);
            }
        }
    });

    footerNext?.addEventListener('click', () => {
        if (!visibleLocationStep.classList.contains('is-active') || !isComplete()) {
            return;
        }

        if (/^\d+$/.test(String(venueValue.value))) {
            return;
        }

        const visible = venueInput.value;
        venueInput.value = locationLabel();
        window.setTimeout(() => {
            if (!/^\d+$/.test(String(venueValue.value))) {
                venueInput.value = visible;
            }
        }, 0);
    }, { capture: true });

    const stepObserver = new MutationObserver(() => {
        if (!visibleLocationStep.classList.contains('is-active')) {
            return;
        }
        const copy = panel.querySelector('.home-flow-step-copy');
        const title = copy?.querySelector('strong');
        const description = copy?.querySelector('span');
        if (title) {
            title.textContent = 'Где искать мероприятие?';
        }
        if (description) {
            description.textContent = 'Оставьте любую локацию или уточните город, район, метро, улицу и площадку.';
        }
        syncOuterState();
    });
    stepObserver.observe(visibleLocationStep, { attributes: true, attributeFilter: ['class'] });

    flow.addEventListener('click', (event) => {
        if (event.target.closest('[data-home-flow-type]')) {
            window.setTimeout(() => reset('any'), 0);
        }
    });

    $(document).on('modal:opened.homeEventLocation', function (_event, modal) {
        if (modal.find('[data-home-flow="event"]').get(0) === flow) {
            window.setTimeout(() => reset('any'), 0);
        }
    });

    $(document).on('click.homeEventLocation', '[data-home-flow="event"] [data-home-flow-tab="search"]', function () {
        window.setTimeout(() => reset('any'), 0);
    });

    reset('any');
    streetHint.textContent = 'Подсказки Яндекса будут ограничены выбранным городом, если он указан.';

    return { reset, state };
}

const eventFlow = document.querySelector('[data-home-flow="event"]');
initHomeEventLocation(eventFlow);
