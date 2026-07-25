document.querySelectorAll('[data-coordination-form]').forEach(initCoordinationForm);

function initCoordinationForm(form) {
    const container = form.querySelector('[data-coordination-options]');
    const subjectType = form.querySelector('[data-coordination-subject-type]');
    const venueData = form.querySelector('[data-coordination-venue-options]');
    const flowType = form.querySelector('[data-coordination-flow-type]');
    const singleFlow = form.querySelector('[data-coordination-single-flow]');
    const chainFlow = form.querySelector('[data-coordination-chain-flow]');

    if (!container || !subjectType) {
        return;
    }

    const syncFlow = () => {
        const chainSelected = flowType?.value === 'event_scheduling';
        if (singleFlow) {
            singleFlow.hidden = chainSelected;
            singleFlow.querySelectorAll('input, select, textarea, button').forEach((field) => {
                field.disabled = chainSelected;
            });
        }
        if (chainFlow) {
            chainFlow.hidden = !chainSelected;
            chainFlow.querySelectorAll('input, select, textarea, button').forEach((field) => {
                field.disabled = !chainSelected;
            });
        }
    };

    flowType?.addEventListener('change', syncFlow);
    syncFlow();

    let venues = [];

    try {
        venues = JSON.parse(venueData?.textContent || '[]');
    } catch {
        venues = [];
    }

    container.dataset.nextIndex = String(container.children.length);

    form.addEventListener('click', (event) => {
        const addButton = event.target.closest('[data-coordination-option-add]');

        if (addButton) {
            if (container.children.length >= 20) {
                return;
            }

            const row = createOptionRow(subjectType.value, nextIndex(container), venues);
            container.append(row);
            row.querySelector('input, select')?.focus();
            return;
        }

        const removeButton = event.target.closest('[data-coordination-option-remove]');

        if (removeButton && container.children.length > 2) {
            removeButton.closest('[data-coordination-option-row]')?.remove();
        }
    });

    subjectType.addEventListener('change', () => {
        container.replaceChildren(
            createOptionRow(subjectType.value, 0, venues),
            createOptionRow(subjectType.value, 1, venues),
        );
        container.dataset.nextIndex = '2';
        container.dataset.subjectType = subjectType.value;
        container.querySelector('input, select')?.focus();
    });
}

function nextIndex(container) {
    const index = Number.parseInt(container.dataset.nextIndex || '0', 10);
    container.dataset.nextIndex = String(index + 1);

    return index;
}

function createOptionRow(subjectType, index, venues) {
    const row = document.createElement('div');
    row.className = 'coordination-option-row';
    row.dataset.coordinationOptionRow = '';

    const fields = document.createElement('div');
    fields.className = 'coordination-option-row__fields';
    const name = `options[${index}]`;

    if (subjectType === 'time_interval') {
        fields.classList.add('coordination-option-row__fields--interval');
        fields.append(
            createInput('time', `${name}[starts_at]`, 'Начало интервала'),
            createSeparator(),
            createInput('time', `${name}[ends_at]`, 'Окончание интервала'),
        );
    } else if (subjectType === 'venue') {
        fields.append(createVenueSelect(name, venues));
    } else {
        const inputTypes = {
            date: 'date',
            time: 'time',
            datetime: 'datetime-local',
        };
        fields.append(createInput(inputTypes[subjectType] || 'text', name));
    }

    const remove = document.createElement('button');
    remove.className = 'btn btn--secondary btn--sm';
    remove.type = 'button';
    remove.dataset.coordinationOptionRemove = '';
    remove.setAttribute('aria-label', 'Удалить вариант');
    remove.textContent = '×';
    row.append(fields, remove);

    return row;
}

function createInput(type, name, ariaLabel = null) {
    const input = document.createElement('input');
    input.className = 'form-control';
    input.type = type;
    input.name = name;
    input.required = true;

    if (type === 'text') {
        input.maxLength = 255;
    }

    if (ariaLabel) {
        input.setAttribute('aria-label', ariaLabel);
    }

    return input;
}

function createSeparator() {
    const separator = document.createElement('span');
    separator.className = 'coordination-option-row__separator';
    separator.textContent = '—';

    return separator;
}

function createVenueSelect(name, venues) {
    const select = document.createElement('select');
    select.className = 'form-select';
    select.name = name;
    select.required = true;
    select.add(new Option('Выберите площадку', ''));
    venues.forEach((venue) => select.add(new Option(venue.label, String(venue.id))));

    return select;
}
