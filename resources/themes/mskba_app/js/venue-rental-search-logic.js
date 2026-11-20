// Calendar arithmetic uses UTC fields as a representation of Moscow wall time,
// never the browser's timezone. MSK is UTC+3, with no seasonal clock changes.
const MINUTE = 60000;
const DAY = 86400000;
const wall = now => new Date(now + 180 * MINUTE);
const dateOf = value => value.toISOString().slice(0, 10);
const clockOf = value => value.toISOString().slice(11, 16);
export const addDays = (date, count) => dateOf(new Date(Date.parse(date + 'T00:00:00Z') + count * DAY));
export function rentalDefaults(now = Date.now(), lead = 0, step = 30) {
    const today = dateOf(wall(now));
    const earliest = wall(now + (Math.max(0, lead) + 10) * MINUTE);
    // Round seconds up; never display a minute earlier than the buffer.
    earliest.setUTCSeconds(0, 0);
    if (wall(now).getUTCSeconds() || wall(now).getUTCMilliseconds()) earliest.setUTCMinutes(earliest.getUTCMinutes() + 1);
    const crossesDay = dateOf(wall(now + Math.max(1, step) * MINUTE)) !== today || dateOf(earliest) !== today;
    const date = crossesDay ? addDays(today, 1) : today;
    return {today, date, time: crossesDay ? '00:00' : clockOf(earliest), maxDate: addDays(today, 8)};
}
const minute = time => Number(time.slice(0, 2)) * 60 + Number(time.slice(3, 5));
const endMinute = time => time === '00:00' || time === '23:59' ? 1440 : minute(time);
export function rangeSlots(data, range) {
    if (data.date < range.from || data.date > range.to) return [];
    const lo = data.date === range.from ? minute(range.fromTime) : 0;
    const hi = data.date === range.to ? endMinute(range.toTime) : 1440;
    return data.slots.filter(slot => minute(slot.starts_at) >= lo && endMinute(slot.ends_at) <= hi);
}
export function eligibleRuns(data, range) {
    const slots = rangeSlots(data, range);
    const groups = [];
    let run = [];
    const flush = () => {
        if (data.booking_enabled && !data.stale && run.length * data.step_minutes >= data.minimum_minutes
            && data.maximum_minutes >= data.minimum_minutes) groups.push(...run);
        run = [];
    };
    for (const slot of slots) {
        if (!slot.selectable || slot.status !== 'available') { flush(); continue; }
        if (run.length && run.at(-1).ends_at !== slot.starts_at) flush();
        run.push(slot);
    }
    flush();
    return groups;
}
function prime(date, slot) {
    const weekend = [0, 6].includes(new Date(date + 'T00:00:00Z').getUTCDay());
    const from = weekend ? 600 : 1080, to = weekend ? 1260 : 1320;
    return minute(slot.starts_at) < to && endMinute(slot.ends_at) > from;
}
export function summarizeRental(days, range, incomplete = false) {
    let known = 0, available = 0, primeKnown = 0, primeFree = 0, nightFree = 0, unknown = false, booked = 0;
    for (const day of days) {
        const eligible = new Set(eligibleRuns(day, range));
        unknown ||= Boolean(day.stale || day.refresh_state === 'failed');
        for (const slot of rangeSlots(day, range)) {
            if (slot.status === 'unknown') { unknown = true; continue; }
            if (!['available', 'booked'].includes(slot.status)) continue;
            const minutes = day.step_minutes;
            const isPrime = prime(day.date, slot);
            known += minutes;
            if (isPrime) primeKnown += minutes;
            if (slot.status === 'booked') booked += minutes;
            if (!eligible.has(slot)) continue;
            available += minutes;
            if (isPrime) primeFree += minutes;
            if (minute(slot.starts_at) < 420 || minute(slot.starts_at) >= 1320) nightFree += minutes;
        }
    }
    if (!available) {
        if (unknown || incomplete) return 'Не удалось подтвердить свободное время. Попробуй изменить условия или повторить поиск.';
        if (!known) return 'На это время нет доступного расписания. Попробуй другие даты.';
        if (booked === known) return 'Ой, похоже, всё занято. Давай попробуем другое время?';
        return 'Свободные интервалы есть не всегда достаточной длины для аренды. Попробуй расширить поиск.';
    }
    if (unknown || incomplete) return 'Подходящее время есть, но часть расписания пока не удалось проверить.';
    if (nightFree / available >= .8) return 'Время есть, но в основном поздно ночью или рано утром.';
    if (primeFree === 0 && primeKnown > 0) return 'В прайм-тайм свободного времени нет, зато есть варианты в другое время.';
    if (primeFree === 0) return 'Есть варианты вне прайм-тайма. Посмотри, подходит ли тебе это время.';
    if (available / known >= .7 && primeFree / primeKnown >= .65) return 'Тебе повезло — даже в популярное время большой выбор!';
    if (available / known <= .2) return 'Свободных вариантов немного, но кое-что удалось найти.';
    if (primeFree / primeKnown <= .25) return 'Популярное время быстро разбирают, но подходящие варианты ещё есть.';
    return 'Неплохой выбор! Посмотри свободные интервалы и подбери удобное время.';
}
export function groupRentalSlots(slots) {
    const groups = [];
    for (const [index, slot] of slots.entries()) {
        const prev = groups.at(-1);
        if (slot.status === 'booked' && prev?.first.status === 'booked' && prev.last.ends_at === slot.starts_at) {
            prev.last = slot; prev.lastIndex = index; prev.count++;
        } else groups.push({first:slot,last:slot,firstIndex:index,lastIndex:index,count:1});
    }
    return groups;
}
