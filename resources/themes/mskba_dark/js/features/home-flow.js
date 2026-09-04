import $ from 'jquery';

const eventWizards = new WeakMap();
const EVENT_TYPE_VALUES = ['game', 'training', 'game_training', 'tournament'];
const EVENT_STEP_COPY = [
    ['Выберите тип мероприятия', 'После выбора сразу перейдём к локации.'],
    ['Где хотите играть?', 'Укажите площадку, адрес, район или метро. Можно оставить любую локацию.'],
    ['Когда удобно?', 'Укажите диапазон дат или сразу посмотрите все подходящие мероприятия.'],
];

function activatePanel(modal, section) {
    const requested = section === 'create' ? 'create' : 'search';
    modal.find('[data-home-flow-panel]').each(function () {
        this.hidden = this.dataset.homeFlowPanel !== requested;
    });
    modal.find('[data-home-flow-tab]').each(function () {
        const active = this.dataset.homeFlowTab === requested;
        $(this).toggleClass('is-active', active);
        this.setAttribute('aria-pressed', String(active));
    });
}

function normalizeLabel(value) {
    return String(value || '').replace(/\s+/g, ' ').trim();
}

function prepareEventWizard(flow) {
    if (!flow || flow.dataset.homeFlow !== 'event') {
        return null;
    }

    if (eventWizards.has(flow)) {
        return eventWizards.get(flow);
    }

    const panel = flow.querySelector('[data-home-flow-panel="search"]');
    const steps = panel?.querySelector('.home-flow-steps');
    const progressItems = steps ? [...steps.querySelectorAll('span')] : [];
    const typeGrid = panel?.querySelector('.home-flow-type-grid');
    const typeButtons = typeGrid ? [...typeGrid.querySelectorAll('button')] : [];
    const locationField = panel?.querySelector('.home-flow-field');
    const locationInput = locationField?.querySelector('input');
    const dateRow = panel?.querySelector('.home-flow-row');
    const dateFields = dateRow ? [...dateRow.querySelectorAll('.home-flow-field')] : [];
    const startInput = dateFields[0]?.querySelector('input');
    const endInput = dateFields[1]?.querySelector('input');
    const metroButton = dateRow?.querySelector('.home-flow-filter');
    const actions = panel?.querySelector('.home-flow-modal__actions');
    const catalogLink = actions?.querySelector('a');
    const note = panel?.querySelector('.home-flow-note');

    if (!panel || progressItems.length < 3 || typeButtons.length === 0 || !locationField || !locationInput || !dateRow || !startInput || !endInput || !actions) {
        return null;
    }

    const total = document.createElement('div');
    total.className = 'home-flow-total';
    total.hidden = true;
    total.innerHTML = '<span class="home-flow-total__label">Вы выбрали</span><div class="home-flow-total__items"></div>';

    const stepCopy = document.createElement('div');
    stepCopy.className = 'home-flow-step-copy';
    stepCopy.innerHTML = '<strong></strong><span></span>';

    const locationActions = document.createElement('div');
    locationActions.className = 'home-flow-location-actions';

    if (metroButton) {
        locationActions.append(metroButton);
    }

    const anyLocationButton = document.createElement('button');
    anyLocationButton.type = 'button';
    anyLocationButton.className = 'btn btn--secondary home-flow-location-any';
    anyLocationButton.innerHTML = '<i class="ti ti-world"></i><span>Любая локация</span>';

    const locationNextButton = document.createElement('button');
    locationNextButton.type = 'button';
    locationNextButton.className = 'btn btn--primary home-flow-next';
    locationNextButton.innerHTML = '<span>Продолжить</span><i class="ti ti-arrow-right"></i>';

    locationActions.append(anyLocationButton, locationNextButton);
    steps.after(total);
    total.after(stepCopy);
    locationField.after(locationActions);

    if (catalogLink) {
        catalogLink.textContent = 'Посмотреть все';
    }
    if (note) {
        note.hidden = true;
    }

    const state = {
        step: 0,
        typeValue: '',
        typeLabel: '',
        locationLabel: '',
        anyLocation: false,
        startDate: '',
        endDate: '',
    };

    const totalItems = total.querySelector('.home-flow-total__items');
    const stepTitle = stepCopy.querySelector('strong');
    const stepDescription = stepCopy.querySelector('span');

    typeButtons.forEach((button, index) => {
        button.dataset.homeFlowType = EVENT_TYPE_VALUES[index] || `type_${index + 1}`;
        button.setAttribute('aria-pressed', 'false');
    });

    progressItems.forEach((item, index) => {
        item.dataset.homeFlowStep = String(index);
        item.addEventListener('click', () => navigateBack(index));
        item.addEventListener('keydown', (event) => {
            if ((event.key === 'Enter' || event.key === ' ') && index < state.step) {
                event.preventDefault();
                navigateBack(index);
            }
        });
    });

    function clearDates() {
        state.startDate = '';
        state.endDate = '';
        startInput.value = '';
        endInput.value = '';
        endInput.removeAttribute('min');
    }

    function clearLocationAndDates() {
        state.locationLabel = '';
        state.anyLocation = false;
        locationInput.value = '';
        locationField.classList.remove('has-error');
        clearDates();
    }

    function updateTypeButtons() {
        typeButtons.forEach((button) => {
            const selected = button.dataset.homeFlowType === state.typeValue;
            button.classList.toggle('is-selected', selected);
            button.setAttribute('aria-pressed', String(selected));
        });
    }

    function createSummaryChip(selection) {
        const button = document.createElement('button');
        const label = document.createElement('small');
        const value = document.createElement('strong');
        const icon = document.createElement('i');

        button.type = 'button';
        button.className = 'home-flow-total__chip';
        button.dataset.homeFlowSummaryStep = String(selection.step);
        label.textContent = selection.label;
        value.textContent = selection.value;
        icon.className = 'ti ti-pencil';

        button.append(label, value, icon);
        button.addEventListener('click', () => navigateBack(selection.step));
        return button;
    }

    function renderTotal() {
        const selections = [];
        if (state.step >= 1 && state.typeLabel) {
            selections.push({ step: 0, label: 'Тип', value: state.typeLabel });
        }
        if (state.step >= 2 && state.locationLabel) {
            selections.push({ step: 1, label: 'Где', value: state.locationLabel });
        }

        total.hidden = selections.length === 0;
        totalItems.replaceChildren(...selections.map(createSummaryChip));
    }

    function setStep(step) {
        state.step = Math.max(0, Math.min(2, step));

        progressItems.forEach((item, index) => {
            const active = index === state.step;
            const complete = index < state.step;
            item.classList.toggle('is-active', active);
            item.classList.toggle('is-complete', complete);
            item.setAttribute('aria-current', active ? 'step' : 'false');
            item.tabIndex = complete ? 0 : -1;
            item.setAttribute('role', complete ? 'button' : 'presentation');
        });

        typeGrid.hidden = state.step !== 0;
        locationField.hidden = state.step !== 1;
        locationActions.hidden = state.step !== 1;
        dateRow.hidden = state.step !== 2;
        actions.hidden = state.step !== 2;

        const copy = EVENT_STEP_COPY[state.step];
        stepTitle.textContent = copy[0];
        stepDescription.textContent = copy[1];

        updateTypeButtons();
        renderTotal();
    }

    function navigateBack(targetStep) {
        if (targetStep >= state.step || targetStep < 0) {
            return;
        }

        if (targetStep === 0) {
            clearLocationAndDates();
        } else if (targetStep === 1) {
            clearDates();
        }

        setStep(targetStep);

        window.setTimeout(() => {
            if (targetStep === 1) {
                locationInput.focus();
            }
        }, 0);
    }

    function commitLocation(anyLocation = false) {
        const value = normalizeLabel(locationInput.value);
        if (!anyLocation && !value) {
            locationField.classList.add('has-error');
            locationInput.focus();
            return;
        }

        locationField.classList.remove('has-error');
        state.anyLocation = anyLocation;
        state.locationLabel = anyLocation ? 'Любая локация' : value;
        clearDates();
        setStep(2);
        window.setTimeout(() => startInput.focus(), 0);
    }

    function syncDates() {
        state.startDate = startInput.value;
        state.endDate = endInput.value;
        if (state.startDate) {
            endInput.min = state.startDate;
            if (state.endDate && state.endDate < state.startDate) {
                state.endDate = '';
                endInput.value = '';
            }
        } else {
            endInput.removeAttribute('min');
        }
    }

    function reset() {
        state.step = 0;
        state.typeValue = '';
        state.typeLabel = '';
        clearLocationAndDates();
        updateTypeButtons();
        setStep(0);
    }

    typeButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const value = button.dataset.homeFlowType || '';
            const changed = value !== state.typeValue;
            state.typeValue = value;
            state.typeLabel = normalizeLabel(button.textContent);
            if (changed) {
                clearLocationAndDates();
            }
            setStep(1);
            window.setTimeout(() => locationInput.focus(), 0);
        });
    });

    locationInput.addEventListener('input', () => {
        locationField.classList.remove('has-error');
    });
    locationInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            commitLocation(false);
        }
    });
    locationNextButton.addEventListener('click', () => commitLocation(false));
    anyLocationButton.addEventListener('click', () => commitLocation(true));

    startInput.addEventListener('change', () => {
        syncDates();
        if (state.startDate && !state.endDate) {
            window.setTimeout(() => endInput.focus(), 0);
        }
    });
    endInput.addEventListener('change', syncDates);

    setStep(0);

    const api = { reset, setStep };
    eventWizards.set(flow, api);
    return api;
}

$(document).on('modal:opened', function (_event, modal) {
    const flow = modal.find('[data-home-flow]');
    if (!flow.length) {
        return;
    }

    const requested = String(modal.data('modalInitialSection') || 'search');
    activatePanel(modal, requested);
    modal.removeData('modalInitialSection');

    const wizard = prepareEventWizard(flow.get(0));
    if (wizard && requested !== 'create') {
        wizard.reset();
    }
});

$(document).on('click', '[data-home-flow-tab]', function () {
    const modal = $(this).closest('[data-modal]');
    if (!modal.length) {
        return;
    }

    const requested = String(this.dataset.homeFlowTab || 'search');
    activatePanel(modal, requested);

    const wizard = prepareEventWizard(modal.find('[data-home-flow]').get(0));
    if (wizard && requested === 'search') {
        wizard.reset();
    }
});
