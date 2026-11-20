import { addDays, rentalDefaults, summarizeRental, rangeSlots, eligibleRuns, groupRentalSlots } from './venue-rental-search-logic.js';

function criteriaForDay(date, criteria) {
    return date >= criteria.from && date <= criteria.to ? criteria
        : {from:date, to:date, fromTime:'00:00', toTime:'23:59'};
}

const dialog = document.querySelector('[data-rental-search-dialog]');
const result = document.querySelector('[data-venue-day-dialog]');
if (dialog && result) {
    const field = selector => dialog.querySelector(selector);
    const form = field('[data-rental-search-form]'), from = field('[data-rental-from]'),
        fromTime = field('[data-rental-from-time]'), to = field('[data-rental-to]'),
        toTime = field('[data-rental-to-time]'), range = field('[data-rental-range]'),
        endFields = field('[data-rental-end-fields]'), error = field('[data-rental-search-error]'),
        next = field('[data-rental-search-next]');
    const courts = JSON.parse(dialog.dataset.courts || '[]');
    const availabilityCourts = JSON.parse(dialog.dataset.availabilityCourts || '[]');
    let pending = null, defaults, transfer = false, readOnly = false, selectedCourt = '';
    function startAvailability(detail = {}) {
        readOnly = true;
        selectedCourt = String(detail.courtId || '');
        open();
        if (detail.date && detail.date >= from.min && detail.date <= from.max) {
            from.value = detail.date;
            if (detail.time) fromTime.value = detail.time;
            resetEnd();
        }
    }
    dialog.addEventListener('mskba:availability-open', event => startAvailability(event.detail));
    function showError(message = '') {
        error.textContent = message;
        error.hidden = !message;
    }
    function resetEnd() {
        to.min = from.value;
        to.max = defaults.maxDate;
        to.value = addDays(from.value, 1) <= to.max ? addDays(from.value, 1) : from.value;
        toTime.value = '23:59';
    }
    function syncRange() {
        endFields.hidden = !range.checked;
        to.disabled = toTime.disabled = !range.checked;
        range.setAttribute('aria-expanded', String(range.checked));
    }
    function open(reset = true) {
        dialog.querySelector('#rental-search-title').textContent = readOnly ? 'Занятость площадки' : 'Арендовать площадку';
        if (reset) {
            defaults = rentalDefaults(Date.now(), readOnly ? 0 : Number(dialog.dataset.lead), Number(dialog.dataset.step));
            from.min = defaults.today;
            const lastCalendarDay = Array.from(result.querySelectorAll('[data-venue-day-date]')).at(-1)?.dataset.venueDayDate;
            if (lastCalendarDay && lastCalendarDay < defaults.maxDate) defaults.maxDate = lastCalendarDay;
            from.max = defaults.maxDate;
            from.value = defaults.date;
            fromTime.value = defaults.time;
            range.checked = false;
            resetEnd();
            syncRange();
        }
        showError();
        if (!dialog.open) dialog.showModal();
        field('[data-rental-search-close]').focus({preventScroll:true});
    }
    document.addEventListener('click', event => {
        const availabilityButton = event.target.closest?.('[data-venue-availability-open]');
        if (availabilityButton) { event.preventDefault(); startAvailability(); return; }
        const button = event.target.closest?.('[data-venue-rental-open]');
        if (!button) return;
        event.preventDefault();
        readOnly = false; selectedCourt = '';
        open();
    });
    from.addEventListener('change', () => {
        if (!from.value) return;
        fromTime.value = from.value === defaults.today
            ? (defaults.date === defaults.today ? defaults.time : '23:59') : '00:00';
        resetEnd();
        showError();
    });
    range.addEventListener('change', () => { if (range.checked && from.value) resetEnd(); syncRange(); showError(); });
    for (const input of [fromTime, to, toTime]) input.addEventListener('input', () => showError());
    field('[data-rental-search-close]').addEventListener('click', () => dialog.close());
    let outside = false;
    dialog.addEventListener('pointerdown', event => { outside = event.target === dialog; });
    dialog.addEventListener('pointerup', event => { if (outside && event.target === dialog) dialog.close(); outside = false; });
    dialog.addEventListener('pointercancel', () => { outside = false; });
    dialog.addEventListener('close', () => {
        if (!transfer) pending?.abort();
        transfer = false;
    });
    form.addEventListener('keydown', event => {
        if (event.key !== 'Enter' || !['date','time'].includes(event.target.type)) return;
        event.preventDefault();
        const fields = [from, fromTime, range, to, toTime].filter(input => !input.disabled);
        (fields[fields.indexOf(event.target) + 1] || next).focus();
    });
    async function searchCourt(court, criteria, signal) {
        const url = new URL(result.dataset.dayUrl, window.location.href);
        for (const [key, value] of Object.entries({
            venue_court_id: court.id, date: criteria.from, search_until: criteria.to,
            search_from_time: criteria.fromTime, search_to_time: criteria.toTime,
        })) url.searchParams.set(key, String(value));
        const response = await fetch(url, {credentials:'same-origin',headers:{Accept:'application/json'},cache:'no-store',signal});
        if (!response.ok) throw new Error('search');
        const data = await response.json();
        if (!Array.isArray(data.days)) throw new Error('search');
        return {...court, days:data.days};
    }
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (pending) return;
        const criteria = {from:from.value,fromTime:fromTime.value,
            to:range.checked ? to.value : from.value,toTime:range.checked ? toTime.value : '23:59'};
        if (!form.reportValidity() || criteria.from < defaults.today || criteria.to > defaults.maxDate ||
            criteria.from > criteria.to || (criteria.from === criteria.to && criteria.fromTime >= criteria.toTime && criteria.toTime !== '23:59')) {
            showError('Проверь даты и время: конец диапазона должен быть позже начала.');
            return;
        }
        const searchCourts = readOnly ? availabilityCourts.filter(court => !selectedCourt || String(court.id) === selectedCourt) : courts;
        if (!searchCourts.length) { showError('У площадки пока нет доступных залов.'); return; }
        showError();
        const controller = new AbortController();
        pending = controller;
        next.disabled = true;
        next.textContent = 'Проверяем расписание…';
        try {
            const results = [];
            // Limit concurrent requests, even when a venue has many halls.
            for (let index = 0; index < searchCourts.length; index += 3) {
                const batch = await Promise.allSettled(searchCourts.slice(index,index+3)
                    .map(court => searchCourt(court,criteria,controller.signal)));
                if (controller.signal.aborted) return;
                results.push(...batch.map((item, i) => item.status === 'fulfilled'
                    ? item.value : {...searchCourts[index+i], days:[], failed:true}));
            }
            if (controller.signal.aborted || !dialog.open) return;
            const days = results.flatMap(court => court.days);
            const first = results.find(court => court.days.some(day => eligibleRuns(day,criteria).length)) || results[0];
            transfer = true;
            dialog.close();
            result.dispatchEvent(new CustomEvent('mskba:rental-search', {detail:{
                criteria, readOnly, courts:results, courtId:first.id,
                date:first.days.find(day => eligibleRuns(day,criteria).length)?.date || criteria.from,
                note:readOnly ? 'Занятость по выбранным датам. Просмотр расписания не создаёт бронь.' : summarizeRental(days,criteria,results.some(court => court.failed)),
                // Outside the original dates, browse the entire day without
                // changing the remembered request or its summary.
                filterSlots:data => rangeSlots(data, criteriaForDay(data.date, criteria)),
                eligibleSlots:data => eligibleRuns(data, criteriaForDay(data.date, criteria)),
                groupSlots:groupRentalSlots,
                back:() => open(false),
            }}));
        } finally {
            if (pending === controller) pending = null;
            next.disabled = false;
            next.textContent = 'Далее';
        }
    });
}
