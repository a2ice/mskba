function setupVenueScheduleForm() {
    const days = Array.from(document.querySelectorAll('[data-venue-schedule-day]'));
    const applyAllButton = document.querySelector('[data-venue-schedule-apply-all]');
    const resetAllButton = document.querySelector('[data-venue-schedule-reset-all]');

    days.forEach((day) => {
        const addButton = day.querySelector('[data-venue-schedule-add-interval]');
        const intervals = Array.from(day.querySelectorAll('[data-venue-schedule-interval]'));
        const state = day.querySelector('[data-venue-schedule-day-state]');

        function visibleIntervals() {
            return intervals.filter((interval) => !interval.hidden);
        }

        function intervalHasValue(interval) {
            return Array.from(interval.querySelectorAll('input')).some((input) => input.value !== '');
        }

        function clearInterval(interval) {
            interval.querySelectorAll('input').forEach((input) => {
                input.value = '';
            });
        }

        function normalizeIntervals() {
            const values = intervals
                .map((interval) => intervalValues(interval))
                .filter((value) => value.startsAt || value.endsAt);

            intervals.forEach((interval, index) => {
                const value = values[index] || { startsAt: '', endsAt: '' };
                setIntervalValues(interval, value);
                interval.hidden = index > 0 && !value.startsAt && !value.endsAt;
            });
        }

        function updateState() {
            const filledCount = visibleIntervals().filter(intervalHasValue).length;

            if (state) {
                state.textContent = filledCount === 0
                    ? 'Выходной'
                    : `${filledCount} ${pluralizeInterval(filledCount)}`;
            }

            if (addButton) {
                addButton.hidden = visibleIntervals().length >= intervals.length;
            }
        }

        addButton?.addEventListener('click', () => {
            const nextInterval = intervals.find((interval) => interval.hidden);

            if (!nextInterval) {
                updateState();
                return;
            }

            nextInterval.hidden = false;
            nextInterval.querySelector('input')?.focus();
            updateState();
        });

        day.querySelectorAll('[data-venue-schedule-remove-interval]').forEach((button) => {
            button.addEventListener('click', () => {
                const interval = button.closest('[data-venue-schedule-interval]');

                if (!interval) {
                    return;
                }

                clearInterval(interval);
                normalizeIntervals();
                updateState();
                document.dispatchEvent(new CustomEvent('venue-schedule:changed'));
            });
        });

        day.addEventListener('input', () => {
            updateState();
            document.dispatchEvent(new CustomEvent('venue-schedule:changed'));
        });
        day.addEventListener('change', () => {
            updateState();
            document.dispatchEvent(new CustomEvent('venue-schedule:changed'));
        });
        day.venueScheduleApi = {
            intervals,
            normalizeIntervals,
            updateState,
        };
        updateState();
    });

    applyAllButton?.addEventListener('click', () => {
        const sourceValues = firstFilledDayValues(days);

        if (sourceValues.length === 0) {
            return;
        }

        days.forEach((day) => {
            applyValuesToDay(day, sourceValues);
        });
        document.dispatchEvent(new CustomEvent('venue-schedule:changed'));
    });

    resetAllButton?.addEventListener('click', () => {
        days.forEach((day) => {
            applyValuesToDay(day, []);
        });
        document.dispatchEvent(new CustomEvent('venue-schedule:changed'));
    });

    setupScheduleExceptions();
    setupVenueSlotPricing();
}

function setupVenueSlotPricing() {
    const form = document.querySelector('[data-venue-schedule-form]');
    const dialog = document.querySelector('[data-venue-price-dialog]');
    if (!form || !dialog) return;

    const list = dialog.querySelector('[data-venue-price-list]');
    const body = dialog.querySelector('.account-venue-price-dialog__body');
    const title = dialog.querySelector('[data-venue-price-dialog-title]');
    const empty = dialog.querySelector('[data-venue-price-empty]');
    const stepMinutes = Number(dialog.dataset.timeStep || 0);
    const wholePlaceholder = dialog.dataset.wholePlaceholder || '';
    const halfPlaceholder = dialog.dataset.halfPlaceholder || '';
    let nextIndex = Number(dialog.dataset.nextIndex || 0);
    let currentContext = null;

    const rows = () => Array.from(dialog.querySelectorAll('[data-venue-price-row]'));
    const rowValues = (row) => Array.from(row?.querySelectorAll('input.form-control') || []).map((input) => input.value);
    const hasCustomValue = (row) => rowValues(row).some((value) => value !== '');

    const parseTime = (value) => {
        if (!/^\d{2}:\d{2}$/.test(value || '')) return null;
        const [hours, minutes] = value.split(':').map(Number);
        if (hours > 23 || minutes > 59) return null;
        return (hours * 60) + minutes;
    };

    const formatTime = (minutes) => {
        const normalized = Math.max(0, Math.min(minutes, (24 * 60) - 1));
        return `${String(Math.floor(normalized / 60)).padStart(2, '0')}:${String(normalized % 60).padStart(2, '0')}`;
    };

    const findRow = (dayOfWeek, startsAt) => rows().find((row) => (
        row.dataset.dayOfWeek === String(dayOfWeek) && row.dataset.startsAt === startsAt
    ));

    const currentRows = () => {
        if (!currentContext) return [];
        const start = parseTime(currentContext.startsAt);
        const end = parseTime(currentContext.endsAt);
        if (start === null || end === null || end <= start) return [];

        return rows().filter((row) => {
            const rowStart = parseTime(row.dataset.startsAt);
            return row.dataset.dayOfWeek === String(currentContext.dayOfWeek)
                && rowStart !== null
                && rowStart >= start
                && rowStart < end;
        });
    };

    const resetRow = (row) => {
        row.querySelectorAll('input.form-control').forEach((input) => {
            input.value = '';
        });
        syncPriceButtons();
    };

    const applyRowToCurrentInterval = (sourceRow) => {
        const source = rowValues(sourceRow);
        currentRows().forEach((row) => {
            row.querySelectorAll('input.form-control').forEach((input, index) => {
                input.value = source[index] || '';
            });
        });
        syncPriceButtons();
    };

    const bindRow = (row) => {
        row.querySelectorAll('input.form-control').forEach((input) => {
            input.addEventListener('input', syncPriceButtons);
            input.addEventListener('change', syncPriceButtons);
        });
        row.querySelector('[data-venue-price-apply-all]')?.addEventListener('click', () => {
            applyRowToCurrentInterval(row);
        });
        row.querySelector('[data-venue-price-reset-row]')?.addEventListener('click', () => {
            resetRow(row);
        });
    };

    const createRow = (dayOfWeek, startsAt, endsAt) => {
        if (!list) return null;

        const row = document.createElement('div');
        row.className = 'account-venue-slot-price';
        row.dataset.venuePriceRow = '';
        row.dataset.dayOfWeek = String(dayOfWeek);
        row.dataset.startsAt = startsAt;
        row.innerHTML = `
            <strong class="account-venue-slot-price__time"></strong>
            <input type="hidden" name="slot_prices[${nextIndex}][day_of_week]">
            <input type="hidden" name="slot_prices[${nextIndex}][starts_at]">
            <label><span>Весь зал</span><input class="form-control" inputmode="decimal" name="slot_prices[${nextIndex}][whole_price]"></label>
            <label><span>Половина</span><input class="form-control" inputmode="decimal" name="slot_prices[${nextIndex}][half_price]"></label>
            <div class="account-venue-slot-price__actions">
                <button type="button" class="btn btn--secondary btn--sm" data-venue-price-apply-all>Применить ко всем</button>
                <button type="button" class="btn btn--secondary btn--sm" data-venue-price-reset-row>Сбросить</button>
            </div>
        `;
        nextIndex += 1;

        row.querySelector('strong').textContent = `${startsAt}–${endsAt}`;
        const hidden = row.querySelectorAll('input[type="hidden"]');
        hidden[0].value = String(dayOfWeek);
        hidden[1].value = startsAt;
        const controls = row.querySelectorAll('input.form-control');
        controls[0].placeholder = wholePlaceholder;
        controls[1].placeholder = halfPlaceholder;
        row.hidden = true;
        list.append(row);
        bindRow(row);
        return row;
    };

    const ensureRows = (dayOfWeek, startsAt, endsAt) => {
        const start = parseTime(startsAt);
        const end = parseTime(endsAt);
        if (start === null || end === null || end <= start || stepMinutes < 1) return [];

        const result = [];
        for (let cursor = start; cursor < end; cursor += stepMinutes) {
            const rowStart = formatTime(cursor);
            const rowEnd = formatTime(Math.min(cursor + stepMinutes, end));
            let row = findRow(dayOfWeek, rowStart);
            if (!row) row = createRow(dayOfWeek, rowStart, rowEnd);
            if (!row) continue;
            row.querySelector('strong').textContent = `${rowStart}–${rowEnd}`;
            result.push(row);
        }
        return result;
    };

    const intervalContext = (interval) => {
        const day = interval?.closest('[data-venue-schedule-day]');
        const values = interval ? intervalValues(interval) : { startsAt: '', endsAt: '' };
        return {
            interval,
            day,
            dayOfWeek: day?.dataset.dayOfWeek || '',
            dayLabel: day?.dataset.dayLabel || '',
            startsAt: values.startsAt,
            endsAt: values.endsAt,
        };
    };

    const showContext = (context) => {
        currentContext = context;
        rows().forEach((row) => { row.hidden = true; });
        const visibleRows = ensureRows(context.dayOfWeek, context.startsAt, context.endsAt);
        visibleRows.forEach((row) => { row.hidden = false; });
        if (title) title.textContent = `${context.dayLabel} · ${context.startsAt || '—'}–${context.endsAt || '—'}`;
        if (empty) {
            empty.hidden = visibleRows.length > 0;
            empty.textContent = visibleRows.length > 0
                ? ''
                : 'Сначала укажите корректное время начала и конца интервала.';
        }
        if (body) body.scrollTop = 0;
        return visibleRows;
    };

    const openDialog = (context) => {
        showContext(context);
        if (typeof dialog.showModal === 'function') dialog.showModal();
        else dialog.setAttribute('open', '');
    };

    const closeDialog = () => {
        if (typeof dialog.close === 'function') dialog.close();
        else dialog.removeAttribute('open');
        syncPriceButtons();
    };

    function syncPriceButtons() {
        document.querySelectorAll('[data-venue-prices-open]').forEach((button) => {
            const interval = button.closest('[data-venue-schedule-interval]');
            const context = intervalContext(interval);
            const start = parseTime(context.startsAt);
            const end = parseTime(context.endsAt);
            const valid = start !== null && end !== null && end > start;
            button.disabled = !valid;
            button.title = valid ? '' : 'Сначала укажите начало и конец интервала';

            const configured = valid && rows().some((row) => {
                const rowStart = parseTime(row.dataset.startsAt);
                return row.dataset.dayOfWeek === String(context.dayOfWeek)
                    && rowStart !== null
                    && rowStart >= start
                    && rowStart < end
                    && hasCustomValue(row);
            });
            button.classList.toggle('is-configured', Boolean(configured));
        });
    }

    rows().forEach(bindRow);

    document.querySelectorAll('[data-venue-prices-open]').forEach((button) => {
        button.addEventListener('click', () => {
            const interval = button.closest('[data-venue-schedule-interval]');
            if (interval) openDialog(intervalContext(interval));
        });
    });

    dialog.querySelectorAll('[data-venue-price-dialog-close]').forEach((button) => {
        button.addEventListener('click', closeDialog);
    });
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) closeDialog();
    });
    dialog.addEventListener('close', syncPriceButtons);

    document.addEventListener('venue-schedule:changed', syncPriceButtons);

    form.addEventListener('submit', () => {
        rows().forEach((row) => {
            const day = document.querySelector(`[data-venue-schedule-day][data-day-of-week="${row.dataset.dayOfWeek}"]`);
            const rowStart = parseTime(row.dataset.startsAt);
            const belongs = Array.from(day?.querySelectorAll('[data-venue-schedule-interval]') || []).some((interval) => {
                if (interval.hidden) return false;
                const values = intervalValues(interval);
                const start = parseTime(values.startsAt);
                const end = parseTime(values.endsAt);
                return start !== null && end !== null && rowStart !== null && rowStart >= start && rowStart < end;
            });
            if (!belongs) row.remove();
        });
    });

    const errorRow = rows().find((row) => row.querySelector('.invalid-feedback'));
    if (errorRow) {
        const day = document.querySelector(`[data-venue-schedule-day][data-day-of-week="${errorRow.dataset.dayOfWeek}"]`);
        const rowStart = parseTime(errorRow.dataset.startsAt);
        const interval = Array.from(day?.querySelectorAll('[data-venue-schedule-interval]') || []).find((candidate) => {
            const values = intervalValues(candidate);
            const start = parseTime(values.startsAt);
            const end = parseTime(values.endsAt);
            return !candidate.hidden && start !== null && end !== null && rowStart !== null && rowStart >= start && rowStart < end;
        });
        if (interval) openDialog(intervalContext(interval));
    }

    syncPriceButtons();
}

function setupScheduleExceptions() {
    const list = document.querySelector('[data-schedule-exception-list]');
    const template = document.querySelector('[data-schedule-exception-template]');
    const addButton = document.querySelector('[data-schedule-exception-add]');
    if (!list || !template || !addButton) {
        return;
    }

    let nextIndex = list.querySelectorAll('[data-schedule-exception]').length;
    const bindRow = (row) => {
        const closed = row.querySelector('[data-schedule-exception-closed]');
        const intervals = row.querySelector('[data-schedule-exception-intervals]');
        const sync = () => {
            if (intervals) intervals.hidden = Boolean(closed?.checked);
            intervals?.querySelectorAll('input').forEach((input) => { input.disabled = Boolean(closed?.checked); });
        };
        closed?.addEventListener('change', sync);
        row.querySelector('[data-schedule-exception-remove]')?.addEventListener('click', () => row.remove());
        sync();
    };

    list.querySelectorAll('[data-schedule-exception]').forEach(bindRow);
    addButton.addEventListener('click', () => {
        const wrapper = document.createElement('div');
        wrapper.innerHTML = template.innerHTML.replaceAll('__INDEX__', String(nextIndex++));
        const row = wrapper.firstElementChild;
        if (!row) return;
        list.append(row);
        bindRow(row);
        row.querySelector('input[type="date"]')?.focus();
    });
}

function intervalValues(interval) {
    const inputs = interval.querySelectorAll('input');

    return {
        startsAt: inputs[0]?.value || '',
        endsAt: inputs[1]?.value || '',
    };
}

function setIntervalValues(interval, value) {
    const inputs = interval.querySelectorAll('input');

    if (inputs[0]) {
        inputs[0].value = value.startsAt || '';
    }

    if (inputs[1]) {
        inputs[1].value = value.endsAt || '';
    }
}

function dayValues(day) {
    return Array.from(day.querySelectorAll('[data-venue-schedule-interval]'))
        .map((interval) => intervalValues(interval))
        .filter((value) => value.startsAt || value.endsAt);
}

function firstFilledDayValues(days) {
    for (const day of days) {
        const values = dayValues(day);

        if (values.length > 0) {
            return values;
        }
    }

    return [];
}

function applyValuesToDay(day, values) {
    const intervals = Array.from(day.querySelectorAll('[data-venue-schedule-interval]'));

    intervals.forEach((interval, index) => {
        const value = values[index] || { startsAt: '', endsAt: '' };
        setIntervalValues(interval, value);
        interval.hidden = index > 0 && !value.startsAt && !value.endsAt;
    });

    day.venueScheduleApi?.normalizeIntervals();
    day.venueScheduleApi?.updateState();
}

function pluralizeInterval(count) {
    if (count === 1) {
        return 'интервал';
    }

    if (count >= 2 && count <= 4) {
        return 'интервала';
    }

    return 'интервалов';
}

document.addEventListener('DOMContentLoaded', setupVenueScheduleForm);
