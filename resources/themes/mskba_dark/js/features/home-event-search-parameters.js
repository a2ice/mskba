import $ from 'jquery';
import { mountHomeFlowNavigation } from './home-flow-navigation.js';
import '../../css/pages/home-event-search-parameters.css';

const EVENT_STEP_COPY = [
    ['Выберите тип мероприятия', 'Можно искать мероприятия любого типа или выбрать конкретный.'],
    ['Уточните параметры', 'Дополнительные фильтры зависят от выбранного типа мероприятия.'],
    ['Где хотите играть?', 'Выберите площадку или пропустите шаг, если локация не важна.'],
    ['Когда удобно?', 'Укажите диапазон дат, в котором искать подходящие мероприятия.'],
];

const EVENT_TYPES = [
    {
        value: 'any',
        label: 'Не важно',
        icon: 'ti ti-adjustments-off',
        tooltip: 'Покажем игры, тренировки, игровые тренировки и турниры без ограничения по типу.',
    },
    {
        value: 'game',
        label: 'Игра',
        icon: 'ti ti-ball-basketball',
        tooltip: 'Обычная баскетбольная игра. На следующем шаге можно уточнить формат и способ набора участников.',
    },
    {
        value: 'training',
        label: 'Тренировка',
        icon: 'ti ti-barbell',
        tooltip: 'Тренировочное мероприятие без обязательного полноценного матча между командами.',
    },
    {
        value: 'game_training',
        label: 'Игровая тренировка',
        icon: 'ti ti-users-group',
        tooltip: 'Тренировка, в которой основная часть занятия строится вокруг игровых упражнений или матчей.',
    },
    {
        value: 'tournament',
        label: 'Турнир',
        icon: 'ti ti-trophy',
        tooltip: 'Соревнование из нескольких игр с турнирной структурой, участниками и таблицей результатов.',
    },
];

const FORMAT_OPTIONS = [
    { value: 'any', label: 'Не важно', tooltip: 'Не ограничивать поиск конкретным игровым форматом.' },
    { value: 'basketball_5x5', label: 'Баскетбол 5×5', tooltip: 'Классический баскетбол: по пять игроков на площадке от каждой стороны.' },
    { value: 'streetball_3x3', label: 'Стритбол 3×3', tooltip: 'Формат 3×3, обычно на одно кольцо и с тремя игроками от каждой стороны.' },
    { value: 'streetball_1x1', label: 'Стритбол 1×1', tooltip: 'Индивидуальный формат один на один.' },
];

const GAME_MODE_OPTIONS = [
    { value: 'any', label: 'Не важно', tooltip: 'Не ограничивать поиск способом формирования сторон.' },
    { value: 'preformed_teams', label: 'Готовые команды', tooltip: 'В мероприятие заявляются уже сформированные команды.' },
    { value: 'individual_draft', label: 'Отдельные игроки', tooltip: 'Участники записываются отдельно, а стороны формируются из игроков.' },
];

const TOURNAMENT_ENROLLMENT_OPTIONS = [
    { value: 'any', label: 'Не важно', tooltip: 'Не ограничивать поиск порядком набора участников турнира.' },
    { value: 'continuous', label: 'Открытая лига', tooltip: 'Новые команды могут присоединяться к турниру по ходу его проведения, пока набор открыт.' },
    { value: 'fixed_pool', label: 'Фиксированный состав', tooltip: 'Список участников фиксируется перед проведением турнира и затем не расширяется.' },
];

function normalizeLabel(value) {
    return String(value || '').replace(/\s+/g, ' ').trim();
}

function tooltipButton(text, label) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'ui-tooltip-trigger home-event-choice__help';
    button.setAttribute('aria-label', `Подсказка: ${label}`);
    button.dataset.tooltip = text;
    button.textContent = '?';
    button.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
    });
    return button;
}

function createChoiceWrapper(button, tooltip, label, extraClass = '') {
    const wrapper = document.createElement('div');
    wrapper.className = `home-event-choice${extraClass ? ` ${extraClass}` : ''}`;
    wrapper.append(button, tooltipButton(tooltip, label));
    return wrapper;
}

function createParameterGroup({ title, stateKey, options }) {
    const section = document.createElement('section');
    section.className = 'home-event-parameter-group';
    section.dataset.homeEventParameterGroup = stateKey;

    const heading = document.createElement('h3');
    heading.className = 'home-event-parameter-group__title';
    heading.textContent = title;

    const grid = document.createElement('div');
    grid.className = 'home-event-parameter-grid';

    const buttons = [];

    options.forEach((option) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'home-event-parameter-choice__button';
        button.dataset.homeEventParameter = stateKey;
        button.dataset.homeEventParameterValue = option.value;
        button.innerHTML = `<span>${option.label}</span>`;
        button.setAttribute('aria-pressed', 'false');

        const wrapper = createChoiceWrapper(
            button,
            option.tooltip,
            option.label,
            'home-event-choice--parameter',
        );

        grid.append(wrapper);
        buttons.push(button);
    });

    section.append(heading, grid);

    return { section, buttons };
}

function prepareEventSearchFlow(flow) {
    if (!flow || flow.dataset.homeFlow !== 'event') {
        return null;
    }

    const panel = flow.querySelector('[data-home-flow-panel="search"]');
    const steps = panel?.querySelector('.home-flow-steps');
    const typeGrid = panel?.querySelector('.home-flow-type-grid');
    const locationStage = panel?.querySelector('.home-flow-event-venue-stage, .home-flow-field');
    const dateRow = panel?.querySelector('.home-flow-row');
    const legacyActions = panel?.querySelector('.home-flow-modal__actions');
    const catalogLink = legacyActions?.querySelector('a[href]');
    const note = panel?.querySelector('.home-flow-note');

    if (!panel || !steps || !typeGrid || !locationStage || !dateRow) {
        return null;
    }

    flow.dataset.homeEventSearchV2 = '1';

    const eyebrow = flow.querySelector('.home-flow-modal__eyebrow');
    if (eyebrow) {
        eyebrow.textContent = 'Мероприятия';
    }

    // Keep the three legacy progress nodes hidden so the compatibility adapter
    // from 004 stays inert. V2 owns a separate visible progress bar with four
    // real steps and passes those nodes to the shared footer explicitly.
    steps.classList.add('home-event-search-legacy-steps');
    steps.setAttribute('aria-hidden', 'true');

    const visibleSteps = document.createElement('div');
    visibleSteps.className = 'home-flow-steps home-event-search-steps';
    visibleSteps.innerHTML = [
        '<span class="is-active" data-home-event-search-step="0">1 · Тип</span>',
        '<span data-home-event-search-step="1">2 · Параметры</span>',
        '<span data-home-event-search-step="2">3 · Где</span>',
        '<span data-home-event-search-step="3">4 · Когда</span>',
    ].join('');
    steps.after(visibleSteps);

    const progressItems = [...visibleSteps.querySelectorAll('[data-home-event-search-step]')];
    const existingTypeButtons = [...typeGrid.querySelectorAll(':scope > button')];
    typeGrid.classList.remove('home-flow-type-grid');
    typeGrid.classList.add('home-event-type-grid');
    typeGrid.replaceChildren();

    const typeButtons = [];

    EVENT_TYPES.forEach((definition, index) => {
        const button = index === 0
            ? document.createElement('button')
            : (existingTypeButtons[index - 1] || document.createElement('button'));

        button.type = 'button';
        button.dataset.homeFlowType = definition.value;
        button.classList.remove('is-selected');
        button.setAttribute('aria-pressed', 'false');
        button.innerHTML = `<i class="${definition.icon}"></i><span>${definition.label}</span>`;

        typeGrid.append(createChoiceWrapper(button, definition.tooltip, definition.label));
        typeButtons.push(button);
    });

    const summary = document.createElement('div');
    summary.className = 'home-flow-total';
    summary.hidden = true;
    summary.innerHTML = '<span class="home-flow-total__label">Вы выбрали</span><div class="home-flow-total__items"></div>';

    const stepCopy = document.createElement('div');
    stepCopy.className = 'home-flow-step-copy';
    stepCopy.innerHTML = '<strong></strong><span></span>';

    const parametersStage = document.createElement('div');
    parametersStage.className = 'home-event-parameters-stage';
    parametersStage.hidden = true;

    const gameFormatGroup = createParameterGroup({
        title: 'Формат',
        stateKey: 'format',
        options: FORMAT_OPTIONS,
    });
    const gameModeGroup = createParameterGroup({
        title: 'Режим',
        stateKey: 'gameMode',
        options: GAME_MODE_OPTIONS,
    });
    const tournamentFormatGroup = createParameterGroup({
        title: 'Формат',
        stateKey: 'format',
        options: FORMAT_OPTIONS,
    });
    const tournamentEnrollmentGroup = createParameterGroup({
        title: 'Порядок набора',
        stateKey: 'enrollment',
        options: TOURNAMENT_ENROLLMENT_OPTIONS,
    });

    const gameParameters = document.createElement('div');
    gameParameters.className = 'home-event-parameters-set';
    gameParameters.dataset.homeEventParametersFor = 'game';
    gameParameters.append(gameFormatGroup.section, gameModeGroup.section);

    const tournamentParameters = document.createElement('div');
    tournamentParameters.className = 'home-event-parameters-set';
    tournamentParameters.dataset.homeEventParametersFor = 'tournament';
    tournamentParameters.append(tournamentFormatGroup.section, tournamentEnrollmentGroup.section);

    const emptyParameters = document.createElement('div');
    emptyParameters.className = 'home-event-parameters-empty';
    emptyParameters.innerHTML = '<i class="ti ti-adjustments-off"></i><strong></strong><span></span>';

    parametersStage.append(gameParameters, tournamentParameters, emptyParameters);

    visibleSteps.after(summary);
    summary.after(stepCopy);
    typeGrid.after(parametersStage);

    legacyActions?.classList.add('home-flow-navigation__legacy-actions');
    if (note) {
        note.hidden = true;
    }

    const venueSelector = locationStage.querySelector('[data-venue-selector]');
    const venueInput = venueSelector?.querySelector('[data-venue-selector-input]')
        || locationStage.querySelector('input');
    const venueValue = venueSelector?.querySelector('[data-venue-selector-value]');
    const venueClear = venueSelector?.querySelector('[data-venue-selector-clear]');
    const startInput = dateRow.querySelector('input[type="date"]');
    const endInput = [...dateRow.querySelectorAll('input[type="date"]')][1] || null;

    const state = {
        step: 0,
        typeValue: 'any',
        typeLabel: 'Не важно',
        format: 'any',
        gameMode: 'any',
        enrollment: 'any',
        locationLabel: '',
        anyLocation: false,
    };

    const summaryItems = summary.querySelector('.home-flow-total__items');
    const stepTitle = stepCopy.querySelector('strong');
    const stepDescription = stepCopy.querySelector('span');
    let navigation = null;

    function optionLabel(options, value) {
        return options.find((option) => option.value === value)?.label || 'Не важно';
    }

    function resetParameters() {
        state.format = 'any';
        state.gameMode = 'any';
        state.enrollment = 'any';
    }

    function updateTypeButtons() {
        typeButtons.forEach((button) => {
            const selected = button.dataset.homeFlowType === state.typeValue;
            button.classList.toggle('is-selected', selected);
            button.setAttribute('aria-pressed', String(selected));
        });
    }

    function updateParameterButtons() {
        const buttons = parametersStage.querySelectorAll('[data-home-event-parameter]');
        buttons.forEach((button) => {
            const key = button.dataset.homeEventParameter;
            const selected = state[key] === button.dataset.homeEventParameterValue;
            button.classList.toggle('is-selected', selected);
            button.setAttribute('aria-pressed', String(selected));
        });
    }

    function renderParameterStage() {
        const isGame = state.typeValue === 'game';
        const isTournament = state.typeValue === 'tournament';

        gameParameters.hidden = !isGame;
        tournamentParameters.hidden = !isTournament;
        emptyParameters.hidden = isGame || isTournament;

        if (!emptyParameters.hidden) {
            const title = emptyParameters.querySelector('strong');
            const description = emptyParameters.querySelector('span');

            if (state.typeValue === 'any') {
                title.textContent = 'Параметры зависят от типа мероприятия';
                description.textContent = 'Выберите конкретный тип на предыдущем шаге или пропустите этот шаг.';
            } else {
                title.textContent = 'Дополнительных параметров пока нет';
                description.textContent = state.typeValue === 'training'
                    ? 'Для тренировок этот шаг пока не содержит дополнительных фильтров.'
                    : 'Для игровых тренировок этот шаг пока не содержит дополнительных фильтров.';
            }
        }

        updateParameterButtons();
    }

    function createSummaryChip({ label, value, step }) {
        const button = document.createElement('button');
        const small = document.createElement('small');
        const strong = document.createElement('strong');
        const icon = document.createElement('i');

        button.type = 'button';
        button.className = 'home-flow-total__chip';
        button.dataset.homeFlowSummaryStep = String(step);
        small.textContent = label;
        strong.textContent = value;
        icon.className = 'ti ti-pencil';
        button.append(small, strong, icon);
        button.addEventListener('click', () => setStep(step));

        return button;
    }

    function renderSummary() {
        const selections = [];

        if (state.step >= 1) {
            selections.push({ label: 'Тип', value: state.typeLabel, step: 0 });
        }

        if (state.step >= 2 && state.typeValue === 'game') {
            selections.push(
                { label: 'Формат', value: optionLabel(FORMAT_OPTIONS, state.format), step: 1 },
                { label: 'Режим', value: optionLabel(GAME_MODE_OPTIONS, state.gameMode), step: 1 },
            );
        }

        if (state.step >= 2 && state.typeValue === 'tournament') {
            selections.push(
                { label: 'Формат', value: optionLabel(FORMAT_OPTIONS, state.format), step: 1 },
                { label: 'Набор', value: optionLabel(TOURNAMENT_ENROLLMENT_OPTIONS, state.enrollment), step: 1 },
            );
        }

        if (state.step >= 3 && state.locationLabel) {
            selections.push({ label: 'Где', value: state.locationLabel, step: 2 });
        }

        summary.hidden = selections.length === 0;
        summaryItems.replaceChildren(...selections.map(createSummaryChip));
    }

    function setStep(step) {
        state.step = Math.max(0, Math.min(3, Number(step) || 0));

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
        parametersStage.hidden = state.step !== 1;
        locationStage.hidden = state.step !== 2;
        dateRow.hidden = state.step !== 3;

        const copy = EVENT_STEP_COPY[state.step];
        stepTitle.textContent = copy[0];
        stepDescription.textContent = copy[1];

        renderParameterStage();
        renderSummary();
        navigation?.sync();
    }

    function resetVenueSelection() {
        if (venueClear && (venueInput?.value || venueValue?.value)) {
            venueClear.click();
        } else if (venueInput) {
            venueInput.value = '';
        }

        state.locationLabel = '';
        state.anyLocation = false;
    }

    function reset() {
        state.typeValue = 'any';
        state.typeLabel = 'Не важно';
        resetParameters();
        resetVenueSelection();

        if (startInput) {
            startInput.value = '';
        }
        if (endInput) {
            endInput.value = '';
            endInput.removeAttribute('min');
        }

        updateTypeButtons();
        updateParameterButtons();
        setStep(0);
    }

    typeButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const nextType = button.dataset.homeFlowType || 'any';
            const changed = nextType !== state.typeValue;

            state.typeValue = nextType;
            state.typeLabel = normalizeLabel(button.querySelector('span')?.textContent || button.textContent) || 'Не важно';

            if (changed) {
                resetParameters();
                resetVenueSelection();
            }

            updateTypeButtons();
            renderParameterStage();
            renderSummary();
            navigation?.sync();
        });
    });

    parametersStage.querySelectorAll('[data-home-event-parameter]').forEach((button) => {
        button.addEventListener('click', () => {
            const key = button.dataset.homeEventParameter;
            if (!['format', 'gameMode', 'enrollment'].includes(key)) {
                return;
            }

            state[key] = button.dataset.homeEventParameterValue || 'any';
            updateParameterButtons();
            renderSummary();
            navigation?.sync();
        });
    });

    progressItems.forEach((item, index) => {
        item.addEventListener('click', () => {
            if (index < state.step) {
                setStep(index);
            }
        });
        item.addEventListener('keydown', (event) => {
            if ((event.key === 'Enter' || event.key === ' ') && index < state.step) {
                event.preventDefault();
                setStep(index);
            }
        });
    });

    venueInput?.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') {
            return;
        }

        // The old bridge used Enter to auto-advance after predictive selection.
        // V2 keeps selection and navigation separate: only the shared footer
        // changes the main wizard step.
        event.preventDefault();
        event.stopImmediatePropagation();
    }, { capture: true });

    venueValue?.addEventListener('change', () => {
        state.anyLocation = false;
        state.locationLabel = venueValue.value
            ? normalizeLabel(venueInput?.value) || 'Выбранная площадка'
            : '';
        navigation?.sync();
    });

    venueInput?.addEventListener('input', () => {
        if (!venueValue?.value) {
            state.locationLabel = '';
        }
        window.setTimeout(() => navigation?.sync(), 0);
    });

    startInput?.addEventListener('change', () => {
        if (endInput) {
            if (startInput.value) {
                endInput.min = startInput.value;
                if (endInput.value && endInput.value < startInput.value) {
                    endInput.value = '';
                }
            } else {
                endInput.removeAttribute('min');
            }
        }
    });

    navigation = mountHomeFlowNavigation({
        flow,
        panel,
        progressItems,

        canNext(step) {
            if (step === 2) {
                return Boolean(venueValue?.value || (!venueValue && normalizeLabel(venueInput?.value)));
            }
            return true;
        },

        canSkip(step) {
            if (step === 1) {
                return ['any', 'training', 'game_training'].includes(state.typeValue);
            }
            return step === 2;
        },

        onBack(step) {
            setStep(step - 1);
        },

        onSkip(step) {
            if (step === 1) {
                setStep(2);
                return;
            }

            if (step === 2) {
                resetVenueSelection();
                state.anyLocation = true;
                state.locationLabel = 'Не важно';
                setStep(3);
            }
        },

        onNext(step) {
            if (step === 0) {
                setStep(1);
                return;
            }

            if (step === 1) {
                setStep(2);
                return;
            }

            if (step === 2) {
                const selected = Boolean(venueValue?.value || (!venueValue && normalizeLabel(venueInput?.value)));
                if (!selected) {
                    venueInput?.focus();
                    return;
                }

                state.anyLocation = false;
                state.locationLabel = normalizeLabel(venueInput?.value) || 'Выбранная площадка';
                setStep(3);
                return;
            }

            catalogLink?.click();
        },
    });

    reset();

    return { reset, setStep, navigation };
}

const eventFlow = document.querySelector('[data-home-flow="event"]');
const eventSearch = prepareEventSearchFlow(eventFlow);

$(document).on('modal:opened.homeEventSearchParameters', function (_event, modal) {
    if (!eventSearch || !modal.find('[data-home-flow="event"]').length) {
        return;
    }

    const panel = eventFlow.querySelector('[data-home-flow-panel="search"]');
    if (!panel?.hidden) {
        eventSearch.reset();
    }
});

$(document).on('click.homeEventSearchParameters', '[data-home-flow="event"] [data-home-flow-tab="search"]', function () {
    window.setTimeout(() => eventSearch?.reset(), 0);
});
