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
            });
        });

        day.addEventListener('input', updateState);
        day.addEventListener('change', updateState);
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
    });

    resetAllButton?.addEventListener('click', () => {
        days.forEach((day) => {
            applyValuesToDay(day, []);
        });
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
