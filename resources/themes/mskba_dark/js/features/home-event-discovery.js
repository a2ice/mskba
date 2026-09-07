import $ from 'jquery';
import '../../css/image-upload.css';
import '../../css/pages/home-event-results.css';

const DISCOVERY_URL = '/home/event-discovery';

function initHomeEventDiscovery(flow) {
    if (!flow || flow.dataset.homeFlow !== 'event') {
        return null;
    }

    const panel = flow.querySelector('[data-home-flow-panel="search"]');
    const progress = panel?.querySelector('.home-event-search-steps');
    const dateRow = panel?.querySelector('.home-flow-row');
    const footer = panel?.querySelector('[data-home-flow-wizard-nav]');
    const backButton = footer?.querySelector('[data-home-flow-wizard-back]');
    const nextButton = footer?.querySelector('[data-home-flow-wizard-next]');
    const skipButton = footer?.querySelector('[data-home-flow-wizard-skip]');
    const stepCopy = panel?.querySelector('.home-flow-step-copy');

    if (!panel || !progress || !dateRow || !footer || !backButton || !nextButton || !stepCopy) {
        return null;
    }

    const existingSteps = [...progress.querySelectorAll('[data-home-event-search-step]')];
    const dateStep = existingSteps[3] || null;
    if (!dateStep) {
        return null;
    }

    const resultsStep = document.createElement('span');
    resultsStep.dataset.homeEventSearchResultsStep = '4';
    resultsStep.textContent = '5 · Результаты';
    progress.append(resultsStep);

    const resultsStage = document.createElement('section');
    resultsStage.className = 'home-event-results';
    resultsStage.dataset.homeEventResultsStage = '';
    resultsStage.hidden = true;
    resultsStage.innerHTML = `
        <div class="home-event-results__surface" data-image-upload-surface aria-live="polite">
            <div class="home-event-results__header">
                <div>
                    <span class="home-event-results__eyebrow">Подходящие мероприятия</span>
                    <strong data-home-event-results-count>Результаты</strong>
                </div>
                <button type="button" class="home-text-link home-event-results__refresh" data-home-event-results-refresh>
                    <i class="ti ti-refresh"></i><span>Обновить</span>
                </button>
            </div>
            <div class="home-event-results__list" data-home-event-results-list></div>
            <div class="home-event-results__empty" data-home-event-results-empty hidden>
                <i class="ti ti-calendar-off"></i>
                <strong>Ничего не найдено</strong>
                <span>Попробуйте изменить тип, параметры, локацию или диапазон дат.</span>
            </div>
            <div class="home-event-results__error" data-home-event-results-error hidden>
                <i class="ti ti-alert-triangle"></i>
                <strong>Не удалось загрузить результаты</strong>
                <span data-home-event-results-error-text>Попробуйте ещё раз.</span>
            </div>
            <span class="image-upload-loading" data-home-event-results-loading role="status" aria-live="polite" hidden>
                <span class="image-upload-loading__spinner" aria-hidden="true"></span>
                <span>Ищем подходящие мероприятия…</span>
            </span>
        </div>
    `;

    panel.insertBefore(resultsStage, footer);

    const surface = resultsStage.querySelector('[data-image-upload-surface]');
    const list = resultsStage.querySelector('[data-home-event-results-list]');
    const count = resultsStage.querySelector('[data-home-event-results-count]');
    const empty = resultsStage.querySelector('[data-home-event-results-empty]');
    const error = resultsStage.querySelector('[data-home-event-results-error]');
    const errorText = resultsStage.querySelector('[data-home-event-results-error-text]');
    const loading = resultsStage.querySelector('[data-home-event-results-loading]');
    const refresh = resultsStage.querySelector('[data-home-event-results-refresh]');
    const nextLabel = nextButton.querySelector('span');
    const nextIcon = nextButton.querySelector('i');
    const copyTitle = stepCopy.querySelector('strong');
    const copyDescription = stepCopy.querySelector('span');
    const dateInputs = [...dateRow.querySelectorAll('input[type="date"]')];
    const dateFrom = dateInputs[0] || null;
    const dateTo = dateInputs[1] || null;

    let inResults = false;
    let requestController = null;
    let requestVersion = 0;

    function selectedType() {
        return flow.querySelector('.home-event-type-grid [data-home-flow-type].is-selected')?.dataset.homeFlowType || 'any';
    }

    function selectedParameter(key) {
        return panel.querySelector(`.home-event-parameters-set:not([hidden]) [data-home-event-parameter="${key}"].is-selected`)
            ?.dataset.homeEventParameterValue || 'any';
    }

    function selectedStreet() {
        const badge = [...flow.querySelectorAll('.home-event-location__badge')].find((item) => (
            item.querySelector('small')?.textContent?.trim() === 'Улица'
        ));

        return badge?.querySelector('strong')?.textContent?.trim() || '';
    }

    function locationFilters() {
        const mode = flow.querySelector('[data-home-event-location-mode].is-selected')?.dataset.homeEventLocationMode || 'any';
        if (mode !== 'specific') {
            return {};
        }

        const city = flow.querySelector('[data-home-location-city]')?.value || '';
        const district = flow.querySelector('[data-home-location-district]')?.value || '';
        const metroSelect = flow.querySelector('[data-venue-selector-metro-filter]');
        const venueValue = flow.querySelector('[data-venue-selector-value]')?.value || '';
        const venueId = /^\d+$/.test(venueValue) ? venueValue : '';
        const metroIds = Array.from(metroSelect?.selectedOptions || [])
            .map((option) => option.value)
            .filter(Boolean);

        return {
            city_id: city,
            district_id: district,
            metro_station_ids: metroIds,
            street: selectedStreet(),
            venue_id: venueId,
        };
    }

    function filters() {
        const type = selectedType();
        const base = {
            type,
            date_from: dateFrom?.value || '',
            date_to: dateTo?.value || '',
            ...locationFilters(),
        };

        if (type === 'game') {
            base.format = selectedParameter('format');
            base.game_mode = selectedParameter('gameMode');
        } else if (type === 'tournament') {
            base.format = selectedParameter('format');
            base.enrollment = selectedParameter('enrollment');
        }

        return base;
    }

    function toQueryString(values) {
        const parameters = new URLSearchParams();

        Object.entries(values).forEach(([key, value]) => {
            if (Array.isArray(value)) {
                value.forEach((item) => {
                    if (String(item || '').trim() !== '') {
                        parameters.append(`${key}[]`, String(item));
                    }
                });
                return;
            }

            if (String(value ?? '').trim() !== '') {
                parameters.set(key, String(value));
            }
        });

        return parameters.toString();
    }

    function setLoading(value) {
        loading.hidden = !value;
        surface.classList.toggle('is-image-upload-loading', value);
        surface.toggleAttribute('aria-busy', value);
        refresh.disabled = value;
    }

    function resetMessages() {
        empty.hidden = true;
        error.hidden = true;
        errorText.textContent = 'Попробуйте ещё раз.';
    }

    function formatDate(result, timezone) {
        const locale = 'ru-RU';
        const dateOptions = { day: 'numeric', month: 'long', timeZone: timezone || undefined };

        if (result.kind === 'tournament') {
            const start = result.starts_on ? new Date(`${result.starts_on}T12:00:00`) : null;
            const end = result.ends_on ? new Date(`${result.ends_on}T12:00:00`) : null;
            if (!start) {
                return '';
            }
            const from = new Intl.DateTimeFormat(locale, dateOptions).format(start);
            if (!end || result.ends_on === result.starts_on) {
                return from;
            }
            return `${from} — ${new Intl.DateTimeFormat(locale, dateOptions).format(end)}`;
        }

        if (!result.starts_at) {
            return '';
        }

        const startsAt = new Date(result.starts_at);
        const date = new Intl.DateTimeFormat(locale, dateOptions).format(startsAt);
        const time = new Intl.DateTimeFormat(locale, {
            hour: '2-digit',
            minute: '2-digit',
            timeZone: timezone || undefined,
        }).format(startsAt);

        return `${date}, ${time}`;
    }

    function addMeta(container, icon, text) {
        if (!text) {
            return;
        }
        const item = document.createElement('span');
        item.className = 'home-event-result-card__meta-item';
        item.innerHTML = `<i class="${icon}" aria-hidden="true"></i>`;
        const value = document.createElement('span');
        value.textContent = text;
        item.append(value);
        container.append(item);
    }

    function addTag(container, text) {
        if (!text) {
            return;
        }
        const tag = document.createElement('span');
        tag.className = 'home-event-result-card__tag';
        tag.textContent = text;
        container.append(tag);
    }

    function resultCard(result, timezone) {
        const card = document.createElement('article');
        card.className = `home-event-result-card home-event-result-card--${result.kind || 'event'}`;

        const head = document.createElement('div');
        head.className = 'home-event-result-card__head';

        const badge = document.createElement('span');
        badge.className = 'home-event-result-card__type';
        badge.textContent = result.type_label || 'Мероприятие';

        const title = document.createElement('a');
        title.className = 'home-event-result-card__title';
        title.href = result.url || '#';
        title.textContent = result.title || 'Мероприятие';

        head.append(badge, title);

        const meta = document.createElement('div');
        meta.className = 'home-event-result-card__meta';
        addMeta(meta, 'ti ti-calendar-event', formatDate(result, timezone));
        addMeta(meta, 'ti ti-map-pin', result.venue?.name || 'Площадка не указана');
        addMeta(meta, 'ti ti-map', result.venue?.address || '');

        const tags = document.createElement('div');
        tags.className = 'home-event-result-card__tags';
        addTag(tags, result.format_label);
        addTag(tags, result.recruitment_mode_label);
        addTag(tags, result.enrollment_policy_label);

        const action = document.createElement('a');
        action.className = 'home-event-result-card__action';
        action.href = result.url || '#';
        action.innerHTML = '<span>Подробнее</span><i class="ti ti-arrow-up-right"></i>';

        card.append(head, meta);
        if (tags.childElementCount) {
            card.append(tags);
        }
        card.append(action);

        return card;
    }

    function renderResults(results, timezone) {
        list.replaceChildren(...results.map((result) => resultCard(result, timezone)));
        count.textContent = results.length === 1 ? '1 результат' : `${results.length} результатов`;
        empty.hidden = results.length !== 0;
    }

    async function loadResults() {
        const values = filters();
        if (!values.date_from || !values.date_to) {
            errorText.textContent = 'Укажите корректный диапазон дат.';
            error.hidden = false;
            dateFrom?.focus();
            return;
        }

        const version = ++requestVersion;
        requestController?.abort();
        const controller = new AbortController();
        requestController = controller;
        resetMessages();
        setLoading(true);

        try {
            const query = toQueryString(values);
            const response = await fetch(`${DISCOVERY_URL}?${query}`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            });
            const payload = await response.json().catch(() => ({}));
            if (version !== requestVersion) {
                return;
            }
            if (!response.ok || !Array.isArray(payload.results)) {
                throw new Error(payload.message || 'Не удалось выполнить поиск.');
            }

            renderResults(payload.results, payload.timezone || '');
        } catch (requestError) {
            if (requestError.name !== 'AbortError' && version === requestVersion) {
                list.replaceChildren();
                count.textContent = 'Результаты';
                errorText.textContent = requestError.message || 'Попробуйте ещё раз.';
                error.hidden = false;
            }
        } finally {
            if (requestController === controller) {
                requestController = null;
                setLoading(false);
            }
        }
    }

    function applyResultsNavigation() {
        if (!inResults) {
            return;
        }
        backButton.disabled = false;
        backButton.setAttribute('aria-disabled', 'false');
        skipButton && (skipButton.hidden = true);
        nextButton.disabled = false;
        nextButton.setAttribute('aria-disabled', 'false');
        if (nextLabel) {
            nextLabel.textContent = 'Готово';
        }
        if (nextIcon) {
            nextIcon.className = 'ti ti-check';
        }
        footer.dataset.homeFlowStep = '4';
    }

    function showResults() {
        if (inResults) {
            loadResults();
            return;
        }

        inResults = true;
        dateStep.classList.remove('is-active');
        dateStep.classList.add('is-complete');
        dateStep.setAttribute('aria-current', 'false');
        resultsStep.classList.add('is-active');
        resultsStep.setAttribute('aria-current', 'step');
        dateRow.hidden = true;
        resultsStage.hidden = false;

        if (copyTitle) {
            copyTitle.textContent = 'Результаты поиска';
        }
        if (copyDescription) {
            copyDescription.textContent = 'Подходящие игры, тренировки, игровые тренировки и турниры собраны в одном списке.';
        }

        window.setTimeout(applyResultsNavigation, 0);
        loadResults();
    }

    function leaveResults() {
        if (!inResults) {
            return;
        }

        inResults = false;
        requestController?.abort();
        requestController = null;
        requestVersion += 1;
        setLoading(false);
        resultsStep.classList.remove('is-active');
        resultsStep.setAttribute('aria-current', 'false');
        dateStep.classList.remove('is-complete');
        dateStep.classList.add('is-active');
        dateStep.setAttribute('aria-current', 'step');
        dateRow.hidden = false;
        resultsStage.hidden = true;

        if (copyTitle) {
            copyTitle.textContent = 'Когда удобно?';
        }
        if (copyDescription) {
            copyDescription.textContent = 'Укажите диапазон дат, в котором искать подходящие мероприятия.';
        }
        if (nextLabel) {
            nextLabel.textContent = 'Далее';
        }
        if (nextIcon) {
            nextIcon.className = 'ti ti-arrow-right';
        }
    }

    function validDates() {
        if (!dateFrom?.value) {
            dateFrom?.focus();
            return false;
        }
        if (!dateTo?.value || dateTo.value < dateFrom.value) {
            dateTo?.focus();
            return false;
        }
        return true;
    }

    nextButton.addEventListener('click', (event) => {
        if (inResults) {
            event.preventDefault();
            event.stopImmediatePropagation();
            flow.closest('[data-modal]')?.querySelector('[data-modal-action="close"]')?.click();
            return;
        }

        if (dateStep.classList.contains('is-active')) {
            event.preventDefault();
            event.stopImmediatePropagation();
            if (validDates()) {
                showResults();
            }
        }
    }, { capture: true });

    backButton.addEventListener('click', (event) => {
        if (!inResults) {
            return;
        }
        event.preventDefault();
        event.stopImmediatePropagation();
        leaveResults();
    }, { capture: true });

    progress.addEventListener('click', (event) => {
        if (!inResults) {
            return;
        }
        const target = event.target.closest('[data-home-event-search-step]');
        if (target) {
            leaveResults();
        }
    }, { capture: true });

    panel.addEventListener('click', (event) => {
        if (inResults && event.target.closest('.home-flow-total__chip')) {
            leaveResults();
        }
    }, { capture: true });

    refresh.addEventListener('click', loadResults);

    $(document).on('modal:opened.homeEventDiscovery', function (_event, modal) {
        if (modal.find('[data-home-flow="event"]').get(0) === flow && inResults) {
            leaveResults();
        }
    });

    $(document).on('click.homeEventDiscovery', '[data-home-flow="event"] [data-home-flow-tab]', function () {
        if (inResults) {
            leaveResults();
        }
    });

    return { showResults, leaveResults, loadResults, filters };
}

const eventFlow = document.querySelector('[data-home-flow="event"]');
initHomeEventDiscovery(eventFlow);
