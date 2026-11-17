import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import { displayClock } from '../../resources/themes/mskba_app/js/venue-time-label.js';
import { groupDayBookingSlots } from '../../resources/themes/mskba_app/js/venue-day-booking-groups.js';

const source = readFileSync(
    new URL('../../resources/themes/mskba_app/js/venue-detail.js', import.meta.url),
    'utf8',
);
const marker = "const dayBookingDialog = document.querySelector('[data-venue-day-dialog]');";
const start = source.indexOf(marker);
assert.ok(start >= 0, 'Daily booking module must exist in venue-detail.js');
const dayBookingModule = source.slice(start);

function node() {
    return {
        dataset: {},
        children: [],
        listeners: {},
        style: {},
        open: false,
        disabled: false,
        hidden: false,
        textContent: '',
        classList: { toggle() {}, add() {} },
        scrollIntoView() {},
        focus(options) { this.focused = true; this.focusOptions = options; },
        click() { this.listeners.click?.(); },
        addEventListener(type, listener) { this.listeners[type] = listener; },
        querySelectorAll() { return []; },
        querySelector() { return null; },
        prepend(...children) { this.children.unshift(...children); },
        append(...children) { this.children.push(...children); },
        appendChild(child) { this.children.push(child); return child; },
        replaceChildren(...children) { this.children = children; },
        setAttribute() {},
        removeAttribute() {},
        showModal() { this.open = true; },
        close() {
            this.open = false;
            this.listeners.close?.();
        },
    };
}

function mountDayBooking({
    previewFails = false, customSlots = null, personalDiscount = null,
    viewerAuthenticated = true, sessionStore = null,
} = {}) {
    const status = node();
    const heading = node();
    const grid = node();
    const bookingButton = node();
    const summary = node();
    const minimum = node();
    const inputs = {
        '[data-venue-day-court]': node(),
        '[data-venue-day-start]': node(),
        '[data-venue-day-duration]': node(),
    };
    const form = node();
    form.querySelector = selector => inputs[selector] || null;
    grid.querySelectorAll = selector =>
        selector === 'button[data-slot-index]' ? grid.children : [];

    const dialog = node();
    dialog.dataset = {
        dayUrl: 'https://dev.mskba.ru/venues/26/schedule/day',
        courtId: '13',
    };
    const fields = {
        '[data-venue-day-status]': status,
        '[data-venue-day-title]': heading,
        '[data-venue-day-slots]': grid,
        '[data-venue-day-booking-form]': form,
        '[data-venue-day-book]': bookingButton,
        '[data-venue-day-summary]': summary,
        '[data-venue-day-minimum]': minimum,
    };
    dialog.querySelector = selector => fields[selector] || null;
    const eventDialog = node();
    const eventFields = {};
    eventDialog.querySelector = selector => eventFields[selector] ||= node();
    const dates = ['2026-11-15', '2026-11-16', '2026-11-17'].map(date => {
        const button = node();
        button.dataset.venueDayDate = date;
        button.querySelector = selector => selector === 'strong'
            ? {textContent: date} : null;
        return button;
    });
    const previous = node();
    previous.dataset.venueDayNav = '-1';
    const next = node();
    next.dataset.venueDayNav = '1';
    dialog.querySelectorAll = selector => selector === '[data-venue-day-date]'
        ? dates : (selector === '[data-venue-day-nav]' ? [previous, next] : []);

    const opener = node();
    opener.dataset.dayDate = '2026-11-15';
    opener.querySelector = () => ({ textContent: '15 ноя' });
    const requests = [];
    const slots = customSlots || [
        { starts_at: '21:00', ends_at: '21:30', selectable: true, status: 'available', price_minor: 357500 },
        { starts_at: '21:30', ends_at: '22:00', selectable: true, status: 'available', price_minor: 357500 },
    ];
    const fetch = async rawUrl => {
        const url = new URL(rawUrl);
        requests.push(url);
        if (url.searchParams.has('preview_start')) {
            if (previewFails) {
                return { ok: false, json: async () => ({ message: 'Стоимость временно недоступна' }) };
            }
            return { ok: true, json: async () => ({
                price_preview: { total_minor: 715000, personal_discount: personalDiscount },
            }) };
        }
        return { ok: true, json: async () => ({
            date: url.searchParams.get('date') || '2026-11-15',
            venue_court_id: Number(url.searchParams.get('venue_court_id') || 13),
            slots,
            booking_enabled: true,
            viewer_authenticated: viewerAuthenticated,
            step_minutes: 30,
            minimum_minutes: 60,
            maximum_minutes: 180,
            refresh_state: 'cached',
        }) };
    };
    const document = {
        querySelector: selector => selector === '[data-venue-day-dialog]' ? dialog
            : (selector === '[data-venue-day-event-dialog]' ? eventDialog : null),
        querySelectorAll: selector => selector === '[data-venue-day-open]' ? [opener] : [],
        createElement: tag => ({...node(), tagName: tag}),
        createTextNode: text => ({textContent: text}),
    };
    const storage = sessionStore || new Map();
    const windowListeners = {};
    const windowEvents = [];
    const window = {
        location: {
            href: 'https://dev.mskba.ru/venues/26-basketpoint-na-dubinskoy',
            pathname: '/venues/26-basketpoint-na-dubninskoy',
            reload() { window.reloads = (window.reloads || 0) + 1; },
        },
        sessionStorage: {
            getItem: key => storage.get(key) || null,
            setItem: (key, value) => storage.set(key, value),
            removeItem: key => storage.delete(key),
        },
        addEventListener: (type, listener) => { windowListeners[type] = listener; },
        dispatchEvent(event) {
            windowEvents.push(event);
            windowListeners[event.type]?.(event);
        },
    };
    class CustomEvent {
        constructor(type, options = {}) { this.type = type; this.detail = options.detail; }
    }
    runInNewContext(dayBookingModule, {
        document, window, CustomEvent,
        AbortController, URL, fetch, displayClock, groupDayBookingSlots,
    }, { filename: 'venue-detail.js' });
    return {
        dialog, eventDialog, eventFields, opener, grid, bookingButton, summary, minimum, inputs, requests, previous, next, dates,
        window, windowEvents, storage,
        async open(expectedCards = 2) {
            await opener.listeners.click();
            assert.equal(grid.children.length, expectedCards);
        },
        clickSlot(index) {
            grid.listeners.click({
                target: { closest: selector =>
                    selector === 'button[data-slot-index]' ? grid.children[index] : null },
            });
        },
    };
}

test('schedule dialog remains fixed to viewport center on iOS Safari', () => {
    const css = readFileSync(
        new URL('../../resources/themes/mskba_app/css/venue-detail.css', import.meta.url), 'utf8',
    );
    const shared = readFileSync(
        new URL('../../resources/themes/mskba_app/css/design-system.css', import.meta.url), 'utf8',
    );
    assert.match(shared, /dialog\\{/);
    // Override the global 50% + transform centering with fixed insets and
    // auto margins, so scrolling the page or changing Safari chrome does not
    // send the schedule (including its booking footer) offscreen.
    assert.match(css, /dialog\\.app-venue-day-dialog\\s*\\{[^}]*position:fixed;[^}]*inset:0;[^}]*margin:auto;[^}]*transform:none;[^}]*height:min\\(86dvh,860px\\)/s);
    assert.match(css, /dialog\\.app-venue-day-dialog\\{[^}]*height:91dvh;max-height:91dvh/s);
});

test('event details stay hidden outside an explicitly opened event modal', () => {
    const css = readFileSync(
        new URL('../../resources/themes/mskba_app/css/venue-detail.css', import.meta.url), 'utf8',
    );
    const template = readFileSync(
        new URL('../../resources/themes/mskba_app/views/pages/venues/partials/day-event-dialog.blade.php', import.meta.url), 'utf8',
    );
    // A closed native dialog must not render as a large visible block above the schedule.
    assert.match(css, /dialog\.app-venue-day-event-dialog:not\(\[open\]\)\s*\{\s*display:none!important\s*\}/);
    assert.match(css, /dialog\.app-venue-day-event-dialog\[open\]\s*\{\s*display:flex/);
    assert.match(template, /<dialog\b[^>]*data-venue-day-event-dialog/);
    assert.doesNotMatch(template, /<dialog\b[^>]*\sopen(?:\s|=|>)/);
    assert.match(template, /data-venue-overlay-close[^>]*autofocus/);
    assert.match(dayBookingModule, /\?\.focus\?\.\(\{ preventScroll: true \}\)/);
    assert.match(dayBookingModule, /const eventInfo = slot\.booking_group\?\.event \|\| null;/);
    assert.match(dayBookingModule, /if \(slot\.status === 'booked'\) \{/);
    assert.match(dayBookingModule, /eventInfoDialog\.showModal\(\);/);
});

test('occupied MSKBA booking without event opens truthful booking details', async () => {
    const page = mountDayBooking({ customSlots: [
        { starts_at: '07:00', ends_at: '07:30', status: 'booked', selectable: false,
          booking_group: { key: 'one-booking', has_event: false, event: null }, price_minor: 181500 },
        { starts_at: '07:30', ends_at: '08:00', status: 'booked', selectable: false,
          booking_group: { key: 'one-booking', has_event: false, event: null }, price_minor: 181500 },
    ] });
    await page.open(1);
    assert.equal(page.grid.children[0].disabled, false, 'An occupied booking must open its details');
    assert.equal(page.grid.children[0].children[1].textContent, 'Занято (m)');
    page.grid.children[0].listeners.click();
    assert.equal(page.eventDialog.open, true);
    assert.equal(page.eventFields['[data-venue-overlay-close]'].focused, true,
        'Initial focus must go to the close button, not the draggable title bar');
    assert.equal(page.eventFields['[data-venue-day-event-title]'].textContent, 'Занятое время');
    assert.equal(page.eventFields['[data-venue-day-event-time]'].textContent, '07:00–08:00');
    assert.equal(page.eventFields['[data-venue-day-event-access]'].textContent, 'Бронирование MSKBA');
    assert.equal(page.eventFields['[data-venue-day-event-type]'].textContent, 'Бронирование');
    assert.equal(page.eventFields['[data-venue-day-event-private-note]'].hidden, true,
        'Unlinked booking must not repeat that no event exists');
    assert.equal(page.eventFields['[data-venue-day-event-link]'].hidden, true);
    assert.equal(page.eventFields['[data-venue-day-event-participation]'].hidden, true);
});

test('occupied imported interval opens generic details but not a fabricated event', async () => {
    const page = mountDayBooking({ customSlots: [
        { starts_at: '08:00', ends_at: '08:30', status: 'booked', selectable: false,
          booking_group: null, price_minor: 181500 },
    ] });
    await page.open(1);
    assert.equal(page.grid.children[0].disabled, false);
    page.grid.children[0].listeners.click();
    assert.equal(page.eventDialog.open, true);
    assert.equal(page.eventFields['[data-venue-day-event-access]'].textContent,
        'Занято по расписанию площадки');
    assert.equal(page.eventFields['[data-venue-day-event-type]'].textContent, 'Занятый интервал');
    assert.equal(page.eventFields['[data-venue-day-event-link]'].hidden, true);
});

test('venue event section excludes booking-only days and disappears without linked events', () => {
    const page = readFileSync(
        new URL('../../resources/themes/mskba_app/views/pages/venues/show.blade.php', import.meta.url), 'utf8',
    );
    const handler = readFileSync(
        new URL('../../app/Modules/Venue/Application/UseCases/ShowVenueHandler.php', import.meta.url), 'utf8',
    );
    const styles = readFileSync(
        new URL('../../resources/themes/mskba_app/css/venue-detail.css', import.meta.url), 'utf8',
    );
    assert.match(handler, /'hasEvent'\s*=>\s*\$event\s*!==\s*null/);
    assert.match(page, /->filter\(fn \(array \$slot\): bool => !empty\(\$slot\['hasEvent'\]\)\)/);
    assert.match(page, /@if\(\$activityDays->isNotEmpty\(\)\)[\s\S]*?<section class="panel app-venue-block" id="app-venue-activities">/);
    assert.match(styles, /\.app-venue-day-dialog__calendar\s*\{[^}]*padding-top:5px/s);
});

test('booking popup shows owner-only create event action, not an invented event', async () => {
    const page = mountDayBooking({viewerAuthenticated: true, customSlots: [
        {starts_at: '07:00', ends_at: '08:00', status: 'booked', selectable: false, price_minor: null,
         booking_group: {
             key: 'owner-booking', has_event: false, event: null,
             create_event_url: '/account/venue-bookings/owner/event/create',
         }},
    ]});
    await page.open(1);
    page.grid.children[0].click();
    assert.equal(page.eventDialog.open, true);
    assert.equal(page.eventFields['[data-venue-day-booking-actions]'].hidden, false);
    assert.equal(page.eventFields['[data-venue-day-booking-create-event]'].hidden, false);
    assert.equal(page.eventFields['[data-venue-day-booking-create-event]'].href,
        '/account/venue-bookings/owner/event/create');
    assert.equal(page.eventFields['[data-venue-day-booking-login]'].hidden, true);
    assert.match(page.eventFields['[data-venue-day-booking-action-status]'].textContent,
        /создать мероприятие/i);
});

test('a non-owner sees no actionable booking URL or login prompt', async () => {
    const page = mountDayBooking({viewerAuthenticated: true, customSlots: [
        {starts_at: '07:00', ends_at: '08:00', status: 'booked', selectable: false, price_minor: null,
         booking_group: {key: 'foreign-booking', has_event: false, event: null, create_event_url: null}},
    ]});
    await page.open(1);
    page.grid.children[0].click();
    assert.equal(page.eventFields['[data-venue-day-booking-create-event]'].hidden, true);
    assert.equal(page.eventFields['[data-venue-day-booking-login]'].hidden, true);
    assert.equal(page.eventFields['[data-venue-day-booking-action-status]'].textContent,
        'Действия недоступны.');
});

test('guest login reopens selected booking after fresh authenticated schedule fetch', async () => {
    const storage = new Map();
    const guestSlots = [
        {starts_at: '07:00', ends_at: '08:00', status: 'booked', selectable: false, price_minor: null,
         booking_group: {key: 'the-booking', has_event: false, event: null, create_event_url: null}},
    ];
    const guest = mountDayBooking({
        viewerAuthenticated: false, sessionStore: storage, customSlots: guestSlots,
    });
    await guest.open(1);
    guest.grid.children[0].click();
    assert.equal(guest.eventFields['[data-venue-day-booking-login]'].hidden, false);
    assert.equal(guest.eventFields['[data-venue-day-booking-action-status]'].textContent,
        'Для просмотра доступных действий авторизуйтесь.');
    guest.eventFields['[data-venue-day-booking-login]'].onclick();
    assert.equal(guest.eventDialog.open, false);
    assert.equal(guest.windowEvents.at(-1).type, 'mskba:auth:open');
    assert.equal(guest.windowEvents.at(-1).detail.stayOnPage, true);
    assert.equal(storage.size, 1);
    guest.window.dispatchEvent({type: 'mskba:auth:success'});
    assert.equal(guest.window.reloads, 1);

    const owner = mountDayBooking({
        viewerAuthenticated: true, sessionStore: storage, customSlots: [
            {...guestSlots[0], booking_group: {...guestSlots[0].booking_group,
                create_event_url: '/account/venue-bookings/owner/event/create'}},
        ],
    });
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(owner.eventDialog.open, true);
    assert.equal(owner.eventFields['[data-venue-day-booking-create-event]'].hidden, false);
    assert.equal(storage.size, 0, 'resume marker must be consumed after reopening');
});

test('booking popup inline type label has a colon and shared row alignment', () => {
    const template = readFileSync(
        new URL('../../resources/themes/mskba_app/views/pages/venues/partials/day-event-dialog.blade.php', import.meta.url), 'utf8',
    );
    const css = readFileSync(
        new URL('../../resources/themes/mskba_app/css/venue-detail.css', import.meta.url), 'utf8',
    );
    assert.match(template, /class="app-venue-day-event-dialog__type-row"><dt>Тип:<\/dt>/);
    assert.match(css, /\.app-venue-day-event-dialog__type-row\s*\{\s*display:flex/);
});

test('private attached event opens minimal popup without leaking details or actions', async () => {
    const event = {
        public: false, visibility: 'По приглашению', type: 'Игра',
        title: null, description: null, url: null, status: null,
        confirmed_count: null, tentative_count: null, max_participants: null,
        organizer_name: null, organizer_user_id: null, participation: null,
    };
    const page = mountDayBooking({ customSlots: [
        { starts_at: '18:00', ends_at: '18:30', status: 'booked', selectable: false,
          booking_group: { key: 'same', has_event: true, event }, price_minor: null },
        { starts_at: '18:30', ends_at: '19:00', status: 'booked', selectable: false,
          booking_group: { key: 'same', has_event: true, event }, price_minor: null },
    ] });
    await page.open(1);
    assert.equal(page.grid.children[0].children[1].textContent, 'Занято (m, e)');
    page.grid.children[0].listeners.click();
    assert.equal(page.eventDialog.open, true);
    assert.equal(page.eventFields['[data-venue-day-event-title]'].textContent, 'Мероприятие');
    assert.equal(page.eventFields['[data-venue-day-event-time]'].textContent, '18:00–19:00');
    assert.equal(page.eventFields['[data-venue-day-event-access]'].textContent, 'По приглашению');
    assert.equal(page.eventFields['[data-venue-day-event-private-note]'].hidden, false);
    assert.equal(page.eventFields['[data-venue-day-event-status-row]'].hidden, true);
    assert.equal(page.eventFields['[data-venue-day-event-count-row]'].hidden, true);
    assert.equal(page.eventFields['[data-venue-day-event-organizer-row]'].hidden, true);
    assert.equal(page.eventFields['[data-venue-day-event-link]'].hidden, true);
    assert.equal(page.eventFields['[data-venue-day-event-participation]'].hidden, true);
});

test('public event offers server-authorized participation from grouped booking popup', async () => {
    const event = {
        public: true, visibility: 'Публичное', type: 'Игра', title: 'Вечерняя игра',
        description: 'Игра 5 на 5', status: 'Опубликовано',
        confirmed_count: 7, tentative_count: 2, max_participants: 10,
        organizer_name: 'Организатор', organizer_user_id: null,
        url: '/events/123', participation: {
            state: 'open', can_join: true, can_tentative: false,
            join_url: '/events/123/participation', join_method: 'PATCH',
        },
    };
    const page = mountDayBooking({ customSlots: [
        { starts_at: '19:00', ends_at: '19:30', status: 'booked', selectable: false,
          booking_group: { key: 'public-booking', has_event: true, event }, price_minor: null },
    ] });
    await page.open(1);
    page.grid.children[0].listeners.click();
    assert.equal(page.eventDialog.open, true);
    assert.equal(page.eventFields['[data-venue-day-event-title]'].textContent, 'Вечерняя игра');
    assert.equal(page.eventFields['[data-venue-day-event-private-note]'].hidden, true);
    assert.equal(page.eventFields['[data-venue-day-event-count-row]'].hidden, false);
    assert.equal(page.eventFields['[data-venue-day-event-count]'].textContent, '7 из 10 · думают: 2');
    assert.equal(page.eventFields['[data-venue-day-event-participation]'].hidden, false);
    assert.equal(page.eventFields['[data-venue-day-event-join-form]'].hidden, false);
    assert.equal(page.eventFields['[data-venue-day-event-join-form]'].action, '/events/123/participation');
    assert.equal(page.eventFields['[data-venue-day-event-join-method]'].value, 'PATCH');
    assert.equal(page.eventFields['[data-venue-day-event-tentative-form]'].hidden, true);
    assert.equal(page.eventFields['[data-venue-day-event-link]'].hidden, false);
    assert.equal(page.eventFields['[data-venue-day-event-link]'].href, '/events/123');
});

for (const audience of ['авторизованный пользователь', 'гость']) {
    test(`два выбранных слота запускают расчёт и разблокируют бронирование: ${audience}`, async () => {
        const page = mountDayBooking();
        await page.open();
        page.clickSlot(0);
        assert.equal(page.bookingButton.disabled, true, 'Минимум 60 минут, один слот не подходит');
        page.clickSlot(1);
        await new Promise(resolve => setImmediate(resolve));

        assert.equal(page.requests.length, 2, 'Расчёт должен отправляться через GET day API');
        assert.equal(page.requests[1].searchParams.get('preview_start'), '21:00');
        assert.equal(page.requests[1].searchParams.get('preview_duration_minutes'), '60');
        assert.equal(page.bookingButton.disabled, false);
        assert.match(page.summary.textContent, /7\s*150/);
        assert.equal(page.inputs['[data-venue-day-court]'].value, '13');
        assert.equal(page.inputs['[data-venue-day-start]'].value, '2026-11-15T21:00');
        assert.equal(page.inputs['[data-venue-day-duration]'].value, '60');
    });
}

test('при ошибке расчёта показывается объяснение вместо бесконечного ожидания', async () => {
    const page = mountDayBooking({ previewFails: true });
    await page.open();
    page.clickSlot(0);
    page.clickSlot(1);
    await new Promise(resolve => setImmediate(resolve));

    assert.equal(page.bookingButton.disabled, true);
    assert.match(page.summary.textContent, /Стоимость временно недоступна/);
});

test('Стрелки и даты изменяют день в пределах доступных девяти суток', async () => {
    const page = mountDayBooking();
    await page.open();
    assert.equal(page.previous.disabled, true);
    assert.equal(page.next.disabled, false);
    page.next.listeners.click();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(page.requests.at(-1).searchParams.get('date'), '2026-11-16');
    assert.equal(page.previous.disabled, false);
    page.next.listeners.click();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(page.requests.at(-1).searchParams.get('date'), '2026-11-17');
    assert.equal(page.next.disabled, true);
    page.previous.listeners.click();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(page.requests.at(-1).searchParams.get('date'), '2026-11-16');
});

test('При переключении зала открывается тот же календарь с выбранным hall id', async () => {
    const page = mountDayBooking();
    await page.open();
    await page.dialog.listeners['mskba:choose-court']({
        detail: {courtId: 99, date: '2026-11-16'},
    });
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(page.requests.at(-1).searchParams.get('venue_court_id'), '99');
    assert.equal(page.requests.at(-1).searchParams.get('date'), '2026-11-16');
});

test('midnight slot labels render 00:00 while quote submission keeps original start date', async () => {
    const page = mountDayBooking({customSlots: [
        {starts_at: '23:00', ends_at: '23:30', selectable: true, status: 'available', price_minor: 357500},
        {starts_at: '23:30', ends_at: '24:00', selectable: true, status: 'available', price_minor: 357500},
    ]});
    await page.open();
    assert.equal(page.grid.children[1].children[0].textContent, '23:30–00:00');
    page.clickSlot(0);
    page.clickSlot(1);
    await new Promise(resolve => setImmediate(resolve));
    assert.match(page.summary.textContent, /23:00\s+–\s+00:00/);
    assert.equal(page.inputs['[data-venue-day-start]'].value, '2026-11-15T23:00');
    assert.equal(page.inputs['[data-venue-day-duration]'].value, '60');
});

test('персональная цена показывает зачёркнутую сумму и безопасную причину', async () => {
    const reason = '<img src=x onerror=alert(1)>';
    const page = mountDayBooking({personalDiscount: {original_amount_minor: 800000, reason}});
    await page.open();
    page.clickSlot(0);
    page.clickSlot(1);
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(page.bookingButton.disabled, false);
    assert.equal(page.summary.children[0].tagName, 'del');
    assert.match(page.summary.children[0].textContent, /8\s*000/);
    assert.equal(page.summary.children.at(-1).title, reason);
    assert.equal(page.summary.children.at(-1).tabIndex, 0);
    assert.equal(page.summary.children.at(-1).innerHTML, undefined);
});
