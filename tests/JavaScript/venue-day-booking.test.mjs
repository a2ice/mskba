import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import { displayClock } from '../../resources/themes/mskba_app/js/venue-time-label.js';
import { groupDayBookingSlots } from '../../resources/themes/mskba_app/js/venue-day-booking-groups.js';
import { nearbyVenueBounds, NEARBY_ZOOM_MARGIN_PX } from '../../resources/themes/mskba_app/js/quick-event-venue-map-geo.js';

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
    viewerAuthenticated = true, sessionStore = null, stale = false, calendarDays = null,
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
    const searchHeader = node(), searchNote = node(), searchCourts = node(), searchBack = node(), calendar = node();
    Object.assign(fields, {
        '[data-rental-result-header]': searchHeader, '[data-rental-result-note]': searchNote,
        '[data-rental-result-courts]': searchCourts, '[data-rental-result-back]': searchBack,
        '[data-venue-day-dates]': calendar,
    });
    searchCourts.querySelectorAll = () => searchCourts.children;
    const dates = (calendarDays || ['2026-11-15', '2026-11-16', '2026-11-17']).map(date => {
        const button = node();
        button.dataset.venueDayDate = date;
        button.parentElement = node();
        button.attributes = {};
        button.setAttribute = (key, value) => {button.attributes[key] = value;};
        button.removeAttribute = key => {delete button.attributes[key];};
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
            refresh_state: stale ? 'updated' : 'cached',
            stale,
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
        window, windowEvents, storage, status, searchNote, searchBack,
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

test('stale FindSport data explains why free slots are not bookable', async () => {
    const page = mountDayBooking({stale: true});
    await page.open(2);
    assert.equal(page.status.hidden, false);
    assert.match(page.status.textContent, /Расписание устарело/);
    assert.equal(page.bookingButton.disabled, true);
});

test('schedule dialog remains fixed to viewport center on iOS Safari', () => {
    const css = readFileSync(
        new URL('../../resources/themes/mskba_app/css/venue-detail.css', import.meta.url), 'utf8',
    );
    const shared = readFileSync(
        new URL('../../resources/themes/mskba_app/css/design-system.css', import.meta.url), 'utf8',
    );
    assert.match(shared, /dialog\s*\{/);
    // Override the global 50% + transform centering with fixed insets and
    // auto margins, so scrolling the page or changing Safari chrome does not
    // send the schedule (including its booking footer) offscreen.
    assert.match(css, /dialog\.app-venue-day-dialog\s*\{[^}]*position:fixed;[^}]*inset:0;[^}]*margin:auto;[^}]*transform:none;[^}]*height:min\(86dvh,860px\)/s);
    assert.match(css, /dialog\.app-venue-day-dialog\{[^}]*height:91dvh;max-height:91dvh/s);
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

test('confirmed booking popup links to a request only for authorized viewers', async () => {
    const requestUrl = '/account/venue-bookings/book-123';
    const permitted = mountDayBooking({ customSlots: [
        { starts_at: '10:00', ends_at: '11:00', status: 'booked', selectable: false,
          booking_group: {
              key: 'owned-booking', has_event: false, event: null,
              request_url: requestUrl, requester_login: 'booking_customer_demo', requester_is_viewer: false,
          }, price_minor: null },
    ] });
    await permitted.open(1);
    permitted.grid.children[0].click();
    assert.equal(permitted.eventFields['[data-venue-day-booking-request-link]'].hidden, false);
    assert.equal(permitted.eventFields['[data-venue-day-booking-request-link]'].href, requestUrl);
    assert.equal(permitted.eventFields['[data-venue-day-booking-requester-row]'].hidden, false);
    assert.equal(permitted.eventFields['[data-venue-day-booking-requester]'].textContent, 'booking_customer_demo');
    assert.equal(permitted.eventFields['[data-venue-day-booking-self]'].hidden, true);
    assert.equal(permitted.eventFields['[data-venue-day-booking-actions]'].hidden, true);

    const client = mountDayBooking({ customSlots: [
        { starts_at: '10:00', ends_at: '11:00', status: 'booked', selectable: false,
          booking_group: {
              key: 'client-booking', has_event: false, event: null,
              request_url: requestUrl, requester_login: 'booking_customer_demo', requester_is_viewer: true,
          }, price_minor: null },
    ] });
    await client.open(1);
    client.grid.children[0].click();
    assert.equal(client.eventFields['[data-venue-day-booking-request-link]'].hidden, false);
    assert.equal(client.eventFields['[data-venue-day-booking-requester-row]'].hidden, false);
    assert.equal(client.eventFields['[data-venue-day-booking-requester]'].textContent, 'booking_customer_demo');
    assert.equal(client.eventFields['[data-venue-day-booking-self]'].hidden, false);

    const outsider = mountDayBooking({ customSlots: [
        { starts_at: '10:00', ends_at: '11:00', status: 'booked', selectable: false,
          booking_group: {
              key: 'foreign-booking', has_event: false, event: null,
              request_url: null, requester_login: null,
          }, price_minor: null },
    ] });
    await outsider.open(1);
    outsider.grid.children[0].click();
    assert.equal(outsider.eventFields['[data-venue-day-booking-request-link]'].hidden, true);
    assert.equal(outsider.eventFields['[data-venue-day-booking-request-link]'].href, undefined);
    assert.equal(outsider.eventFields['[data-venue-day-booking-requester-row]'].hidden, true);
    assert.equal(outsider.eventFields['[data-venue-day-booking-self]'].hidden, true);
    assert.equal(outsider.eventFields['[data-venue-day-booking-action-status]'].textContent, 'Действия недоступны.');

    const template = readFileSync(
        new URL('../../resources/themes/mskba_app/views/pages/venues/partials/day-event-dialog.blade.php', import.meta.url), 'utf8',
    );
    assert.match(template, /class="app-venue-day-event-dialog__request-link" data-venue-day-booking-request-link\s+target="_blank" rel="noopener noreferrer" hidden>К заявке<\/a>/);
    assert.doesNotMatch(template, /class="button secondary" data-venue-day-booking-request-link/);
    assert.match(template, /data-venue-day-booking-self hidden> \(это Вы\)<\/span>/);
    const styles = readFileSync(new URL('../../resources/themes/mskba_app/css/venue-detail.css', import.meta.url), 'utf8');
    assert.match(styles, /app-venue-day-event-dialog__request-link\{[^}]*color:var\(--accent\);[^}]*font-weight:650/s);
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
    assert.match(template, /class="app-venue-day-event-dialog__requester-row" data-venue-day-booking-requester-row hidden>\s*<dt>Заказчик:<\/dt><dd><span data-venue-day-booking-requester><\/span><span class="app-venue-day-event-dialog__requester-self" data-venue-day-booking-self hidden> \(это Вы\)<\/span><\/dd>/);
    assert.match(css, /\.app-venue-day-event-dialog__requester-row\s*\{\s*display:flex;flex-wrap:wrap;align-items:baseline;gap:6px;/);
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

test('multi-court overview never requests slots before selecting a hall', async () => {
    const page = mountDayBooking();
    page.dialog.dataset.courtId = '';
    await page.opener.listeners.click();
    assert.equal(page.requests.length, 0, 'no first-hall fallback is allowed');
    assert.equal(page.dialog.open, false);
    assert.equal(page.windowEvents.at(-1).type, 'mskba:venue-court-selection-required');
    assert.equal(page.windowEvents.at(-1).detail.date, '2026-11-15');

    page.dialog.listeners['mskba:choose-court']({
        detail: {courtId: 99, date: '2026-11-16'},
    });
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(page.requests.at(-1).searchParams.get('venue_court_id'), '99');
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
    assert.equal(page.summary.children.at(-1).tabIndex, undefined,
        'Explanatory discount text must not be in the keyboard tab order');
    assert.equal(page.summary.children.at(-1).innerHTML, undefined);
});


test('Legacy day entry without a selected court still resolves the court explicitly', () => {
    const choiceSource = readFileSync(
        new URL('../../resources/themes/mskba_app/js/venue-choice.js', import.meta.url), 'utf8',
    );
    const run = courtIds => {
        const handlers = {};
        const dispatches = [];
        const select = {
            options: courtIds.map(id => ({value:String(id)})),
            value: courtIds.length === 1 ? String(courtIds[0]) : '',
            addEventListener() {}, focus() {}, setAttribute() {}, removeAttribute() {},
        };
        const close = {addEventListener() {}};
        const dateButton = {addEventListener() {}};
        const choice = {
            open:false, opened:0, listeners:{},
            querySelector:selector => ({
                '[data-venue-choice-court]':select,
                '[data-venue-choice-error]':{hidden:true},
            })[selector],
            querySelectorAll:()=>[close],
            addEventListener(type,callback){this.listeners[type]=callback;},
            showModal(){this.open=true;this.opened++;},
            close(){this.open=false;},
        };
        choice.querySelector=selector=>({
            '[data-venue-choice-court]':select,
            '[data-venue-choice-error]':{hidden:true},
            '[data-venue-choice-date]':dateButton,
        })[selector];
        const day = {dispatchEvent:e=>dispatches.push(e.detail)};
        runInNewContext(choiceSource,{
            document:{
                querySelector:selector=>selector==='[data-venue-choice-dialog]'?choice:day,
                addEventListener:(type,handler)=>{handlers[type]=handler;},
            },
            window:{addEventListener:(type,handler)=>{handlers['window:'+type]=handler;}},
            CustomEvent:class {constructor(type,{detail}){this.type=type;this.detail=detail;}},
            Number,
        });
        handlers['window:mskba:venue-court-selection-required']({detail:{date:'2026-11-15'}});
        return {choice,dispatches,select,handlers};
    };
    const single=run([13]);
    assert.equal(single.choice.opened,0);
    assert.equal(single.dispatches.length,1);
    assert.equal(single.dispatches[0].courtId,13);
    const multiple=run([13,14]);
    assert.equal(multiple.choice.opened,1);
    assert.equal(multiple.dispatches.length,0);
});


test('venue popup retains minimal event list and opens full inline event details', () => {
    const tpl = readFileSync(
        new URL('../../resources/views/components/venue-play-funnel.blade.php', import.meta.url), 'utf8',
    );
    assert.match(tpl, /На этой площадке пока нет предстоящих мероприятий\./);
    assert.match(tpl, /data-venue-play-step="list"/);
    assert.match(tpl, /data-venue-play-preview="{{ \$upcoming->id }}"/);
    assert.match(tpl, /data-venue-play-list-footer/);
    assert.match(tpl, /data-venue-play-step="detail" hidden/);
    assert.match(tpl, /data-venue-play-pane="{{ \$upcoming->id }}"/);
    const discussion = readFileSync(new URL('../../resources/views/components/event-discussion.blade.php', import.meta.url), 'utf8');
    const composer = readFileSync(new URL('../../resources/views/components/chat-composer.blade.php', import.meta.url), 'utf8');
    const eventPage = readFileSync(new URL('../../resources/themes/mskba_app/views/pages/events/event-first-show.blade.php', import.meta.url), 'utf8');
    assert.match(tpl, /@include\('components\.event-discussion'/);
    assert.match(eventPage, /@include\('components\.event-discussion'/);
    assert.match(discussion, /@include\('components\.chat-composer'/);
    assert.match(discussion, /'value' => 'confirmed'/);
    assert.match(discussion, /'value' => 'tentative'/);
    assert.doesNotMatch(discussion, /'value' => 'left'/);
    assert.match(composer, /data-chat-actions/);
    assert.match(composer, /data-chat-context-delete hidden/);
    assert.match(composer, /placeholder="Написать сообщение"/);
    assert.match(composer, /data-venue-play-chars[^>]*>200</);
    assert.doesNotMatch(tpl + discussion + composer, /Обсуждение и действия|не более 3 действий в минуту|0\/200/);
    assert.match(discussion, /data-venue-play-messages/);
    assert.match(composer, /data-venue-play-message-form/);
    assert.match(tpl, /data-venue-play-back/);
    assert.match(tpl, /data-venue-play-auth-notice/);
    assert.doesNotMatch(tpl, /venue-play-dialog__eyebrow|Текущие мероприятия|Выбери ближайшее мероприятие/);
    // Clickable list items are buttons, not links away from the popup.
    assert.doesNotMatch(tpl, /<a class="venue-play-dialog__event"/);
    const js = readFileSync(
        new URL('../../resources/themes/mskba_app/js/venue-play-funnel.js', import.meta.url), 'utf8',
    );
    assert.match(js, /data-venue-play-preview/);
    assert.match(js, /showEvent\(button\.dataset\.venuePlayPreview\)/);
    assert.match(js, /listFooter\.hidden=true/);
    assert.match(js, /listFooter\.hidden=false/);
    const shared = readFileSync(new URL('../../resources/js/event-social-wall.js', import.meta.url), 'utf8');
    assert.match(js, /mountEventDiscussion\(page,/);
    assert.match(js, /mountEventDiscussion\(dialog,/);
    assert.match(shared, /refreshWall\(pane\)/);
    assert.doesNotMatch(js, /function refreshWall|function loadOlder|client_request_id/);
    assert.match(shared, /guestResponse\(status\)/);
    assert.match(shared, /attendance\.dataset\.chatActionValue=confirmed\?'left':'confirmed'/);
    assert.match(shared, /attendance\.textContent=confirmed\?'Не пойду':'Пойду'/);
    assert.match(shared, /200-\[\.\.\.field\.value\]\.length/);
});

test('event creation select controls reuse the branded native select, not custom compact fields', () => {
    const tpl = readFileSync(
        new URL('../../resources/views/components/event-quick-create.blade.php', import.meta.url), 'utf8',
    );
    assert.match(tpl, /mskba-modal quick-event-dialog/);
    assert.match(tpl, /mskba-modal__footer quick-event-dialog__actions/);
    assert.match(tpl, /min-height:48px/);
    assert.match(tpl, /border-radius:12px/);
    assert.match(tpl, /font-size:16px;line-height:24px/);
    assert.equal((tpl.match(/<use href="#chevron-down"\/>/g) || []).length, 5);
    assert.doesNotMatch(tpl, /min-height:43px|quick-event-dialog__eyebrow/);
});


test('favorite modals preserve exact acceptance copy, one action and approved close icon', () => {
    const tpl = readFileSync(
        new URL('../../resources/views/components/venue-main-favorite-dialog.blade.php', import.meta.url), 'utf8',
    );
    const source = readFileSync(
        new URL('../../resources/themes/mskba_app/js/venue-favorite-main.js', import.meta.url), 'utf8',
    );
    assert.match(tpl, /data-venue-favorite-title>Избранное/);
    assert.match(tpl, /Площадка добавлена в избранное\./);
    assert.match(source, /title\.textContent='Избранное'/);
    assert.match(source, /Вы уверены, что хотите убрать площадку из избранного\?/);
    assert.match(source, /'Убрать'/);
    assert.doesNotMatch(tpl, /data-venue-favorite-cancel/);
    assert.equal((tpl.match(/data-venue-favorite-submit/g) || []).length, 1);
    assert.match(tpl, /M18 6l-12 12/);
});

test('rental court selection is a semantic standard select with branded arrow', () => {
    const tpl = readFileSync(
        new URL('../../resources/themes/mskba_app/views/pages/venues/show.blade.php', import.meta.url), 'utf8',
    );
    assert.match(tpl, /<label for="app-venue-choice-court">Зал или корт<\/label>/);
    assert.match(tpl, /data-venue-choice-court required aria-describedby="app-venue-choice-error"/);
    assert.match(tpl, /<use href="#chevron-down"\/>/);
    const css = readFileSync(
        new URL('../../resources/themes/mskba_app/css/design-system.css', import.meta.url), 'utf8',
    );
    assert.match(css, /\.select-control>svg\{[^}]*width:20px;height:20px/);
});


test('event creation uses predictive venue search, map selection, and location-based title', () => {
    const tpl = readFileSync(
        new URL('../../resources/views/components/event-quick-create.blade.php', import.meta.url), 'utf8',
    );
    assert.match(tpl, /data-quick-venue-selector/);
    assert.match(tpl, /data-quick-venue-search/);
    assert.match(tpl, /data-quick-venue-results/);
    assert.match(tpl, /data-quick-venue-map-open>На карте/);
    assert.match(tpl, /data-quick-venue-map-dialog/);
    assert.doesNotMatch(tpl, /<select name="venue_id"/);
    assert.match(tpl, /EventTypeEnum::creatable\(\)/);
    const app = readFileSync(
        new URL('../../resources/themes/mskba_app/js/app.js', import.meta.url), 'utf8',
    );
    const dark = readFileSync(
        new URL('../../resources/themes/mskba_dark/js/app.js', import.meta.url), 'utf8',
    );
    assert.match(app, /quick-event-venue-selector\.js/);
    assert.match(dark, /quick-event-venue-selector\.js/);
    const predictor = readFileSync(
        new URL('../../resources/themes/mskba_app/js/quick-event-venue-selector.js', import.meta.url), 'utf8',
    );
    assert.match(predictor, /venues\.search|dataset\.searchUrl/);
    assert.match(predictor, /venuePlacemark\(/);
    assert.match(predictor, /hidden\.dispatchEvent\(/);
    const wizard = readFileSync(
        new URL('../../resources/themes/mskba_app/js/event-quick-create.js', import.meta.url), 'utf8',
    );
    assert.match(wizard, /data-quick-venue-search/);
    assert.match(wizard, /place\?\.primary_metro/);
    assert.match(wizard, /'на м\. '/);
    assert.match(wizard, /'ул\. '/);
    assert.match(wizard, /if\(editingTitle\)return/);
    assert.match(wizard, /const place=venueSearch\.value\.trim\(\)/);
});

test('game_training remains a legacy readable type, but is not in creation UI', () => {
    const enumSource = readFileSync(
        new URL('../../app/Modules/Event/Domain/Enums/EventTypeEnum.php', import.meta.url), 'utf8',
    );
    assert.match(enumSource, /case GAME_TRAINING = 'game_training'/);
    assert.match(enumSource, /return \[self::GAME, self::TRAINING\]/);
    const menu = readFileSync(
        new URL('../../app/Presentation/Navigation/Menus/MainMenu.php', import.meta.url), 'utf8',
    );
    assert.doesNotMatch(menu, /'Игровые тренировки'/);
    const oldHome = readFileSync(
        new URL('../../resources/themes/mskba_dark/views/pages/welcome.blade.php', import.meta.url), 'utf8',
    );
    assert.doesNotMatch(oldHome, /Создать игровую тренировку/);
    const categories = readFileSync(
        new URL('../../app/Modules/Event/Presentation/Http/Controllers/EventController.php', import.meta.url), 'utf8',
    );
    assert.match(categories, /\$typeFilter/);
    assert.match(categories, /EventTypeEnum::creatable\(\)/);
    assert.match(categories, /EventTypeEnum::GAME_TRAINING/);
});


test('venue map opens a preview card before selecting, with simplified instruction', () => {
    const tpl = readFileSync(
        new URL('../../resources/views/components/event-quick-create.blade.php', import.meta.url), 'utf8',
    );
    const source = readFileSync(
        new URL('../../resources/themes/mskba_app/js/quick-event-venue-selector.js', import.meta.url), 'utf8',
    );
    assert.match(tpl, /Нажмите на площадку на карте\./);
    assert.match(tpl, /data-quick-venue-map-preview/);
    assert.doesNotMatch(tpl, /data-quick-venue-map-preview-label/);
    assert.doesNotMatch(tpl, /data-quick-venue-map-preview-name/);
    assert.match(tpl, /data-quick-venue-map-preview[\s\S]*?hidden><\/div>/);
    assert.match(tpl, /data-quick-venue-map-nearby[\s\S]*?Рядом со мной/);
    assert.match(source, /marker\.events\.add\('click',\(\)=>showPreview\(venue\)\)/);
    assert.match(source, /renderVenueMapInfoCard\(combined,\{/);
    assert.match(source, /showBadge:false/);
    assert.match(source, /compact:true/);
    assert.match(source, /onSelect:chosen=>\{selected\(chosen\);mapDialog\.close\(\);\}/);
    assert.match(source, /fetch\(url\.href,\{headers:\{Accept:'application\/json'/);
    assert.doesNotMatch(source, /marker\.events\.add\('click',[\s\S]{0,90}selected\(venue\)/);
});

test('nearby location mode is optional, reversible and never uploads the position', () => {
    const source = readFileSync(
        new URL('../../resources/themes/mskba_app/js/quick-event-venue-selector.js', import.meta.url), 'utf8',
    );
    assert.match(source, /navigator\.geolocation\.getCurrentPosition/);
    assert.match(source, /nearbyButton\.hidden=false/);
    assert.match(source, /nearbyButton\.textContent='Показать все'/);
    assert.match(source, /nearbyButton\.textContent='Рядом со мной'/);
    assert.match(source, /nearbyButton\.addEventListener\('click',displayNearby\)/);
    assert.match(source, /originalBounds=clusterer\.getBounds\(\)/);
    assert.match(source, /nearbyVenueBounds\(userCoords,plottedVenues\)/);
    assert.match(source, /map\.geoObjects\.add\(userMarker\)/);
    assert.match(source, /preset:'islands#blueCircleDotIcon'/);
    const apiFetch = source.slice(source.indexOf('async function fetchVenues'), source.indexOf('function renderResults'));
    assert.doesNotMatch(apiFetch, /userCoords|latitude|longitude|geolocation/);
    assert.ok(NEARBY_ZOOM_MARGIN_PX >= 48, 'Map edge padding must clear 28px pin + 20px gutter');
});

test('nearby venue bounds include the user at their center and only closest pins', () => {
    const user = {latitude:55.751, longitude:37.618};
    const venues = [
        {id:1, latitude:55.757, longitude:37.618},
        {id:2, latitude:55.751, longitude:37.638},
        {id:3, latitude:55.71, longitude:37.60},
        {id:4, latitude:59.93, longitude:30.3},
        {id:5, latitude:null, longitude:37.6},
    ];
    const result = nearbyVenueBounds(user, venues, 2);
    assert.deepEqual(result.nearest.map(point=>point.id), [1,2]);
    const [[minLat,minLon],[maxLat,maxLon]] = result.bounds;
    assert.ok(minLat <= user.latitude && maxLat >= user.latitude);
    assert.ok(minLon <= user.longitude && maxLon >= user.longitude);
    assert.ok(Math.abs((maxLat+minLat)/2-user.latitude)<1e-9);
    assert.ok(Math.abs((maxLon+minLon)/2-user.longitude)<1e-9);
    assert.equal(nearbyVenueBounds(user,[],5),null);
    assert.equal(nearbyVenueBounds({latitude:100,longitude:0},venues),null);
});


test('homepage and wizard map cards share one renderer and visual contract', () => {
    const shared = readFileSync(
        new URL('../../resources/js/components/venue-map-info-card.js', import.meta.url), 'utf8',
    );
    const homepage = readFileSync(
        new URL('../../resources/js/page-builder.js', import.meta.url), 'utf8',
    );
    const wizard = readFileSync(
        new URL('../../resources/themes/mskba_app/js/quick-event-venue-selector.js', import.meta.url), 'utf8',
    );
    const pageCss = readFileSync(
        new URL('../../resources/css/page-builder.css', import.meta.url), 'utf8',
    );
    const appCss = readFileSync(
        new URL('../../resources/themes/mskba_app/css/app.css', import.meta.url), 'utf8',
    );
    const darkCss = readFileSync(
        new URL('../../resources/themes/mskba_dark/css/app.css', import.meta.url), 'utf8',
    );
    const sharedCss = readFileSync(
        new URL('../../resources/css/venue-map-info-card.css', import.meta.url), 'utf8',
    );
    assert.match(shared, /export function renderVenueMapInfoCard\(/);
    assert.match(shared, /export function attachSharedHomeVenueCards\(/);
    assert.match(shared, /mskba-venue-map-card--compact/);
    assert.match(shared, /venue\.image_url/);
    assert.match(shared, /mskba-venues-map__card-address/);
    assert.match(shared, /mskba-venues-map__card-tags/);
    assert.match(shared, /mskba-venues-map__card-link/);
    assert.match(shared, /data\.venueMapChoose|dataset\.venueMapChoose/);
    assert.match(homepage, /attachSharedHomeVenueCards/);
    assert.match(wizard, /renderVenueMapInfoCard/);
    assert.match(wizard, /item\.preview_url/);
    assert.match(wizard, /payload\?\.venue/);
    assert.match(pageCss, /venue-map-info-card\.css/);
    assert.match(appCss, /venue-map-info-card\.css/);
    assert.match(darkCss, /venue-map-info-card\.css/);
    assert.match(sharedCss, /--venue-map-card-scale:\.9/);
    assert.match(sharedCss, /\.mskba-venues-map__venue-card\.mskba-venue-map-card--compact\s*\{\s*--venue-map-card-scale:\.9/);
    assert.match(sharedCss, /font-size:calc\(16px \* var\(--venue-map-card-scale\)\)/);
});


test('one-finger drag is standard on touch maps while homepage remains cooperative', () => {
    const core = readFileSync(
        new URL('../../resources/themes/mskba_dark/js/core/yandex-maps.js', import.meta.url), 'utf8',
    );
    const home = readFileSync(
        new URL('../../resources/themes/mskba_dark/js/features/home-venue-section.js', import.meta.url), 'utf8',
    );
    const pageBuilder = readFileSync(
        new URL('../../resources/page-builder/venues-map.js', import.meta.url), 'utf8',
    );
    assert.match(core, /const touchMode = element\.dataset\.mapTouchMode \|\| 'default'/);
    assert.match(core, /if \(touchMode === 'cooperative'\)/);
    assert.match(core, /map\.behaviors\.enable\('drag'\)/);
    assert.match(core, /map\.behaviors\.enable\('multiTouch'\)/);
    assert.match(core, /touchMode === 'static'/);
    assert.match(home, /canvas\.dataset\.mapTouchMode = 'cooperative'/);
    assert.match(pageBuilder, /map\.behaviors\.disable\('drag'\)/);
});

test('venue preview uses existing loader and reuses fetched details and loaded card images', () => {
    const shared = readFileSync(
        new URL('../../resources/js/components/venue-map-info-card.js', import.meta.url), 'utf8',
    );
    const css = readFileSync(
        new URL('../../resources/css/venue-map-info-card.css', import.meta.url), 'utf8',
    );
    const chooser = readFileSync(
        new URL('../../resources/themes/mskba_app/js/quick-event-venue-selector.js', import.meta.url), 'utf8',
    );
    assert.match(shared, /function mediaLoadingLayer\(/);
    assert.match(shared, /image-upload-loading__spinner/);
    assert.match(shared, /options\.mediaPending \? mediaLoadingLayer\(\) : placeholder\(\)/);
    assert.match(shared, /img\.loading = 'eager'/);
    assert.match(shared, /img\.addEventListener\('load', showImage/);
    assert.match(shared, /img\.addEventListener\('error'/);
    assert.match(shared, /const cachedCards = new Map\(\)/);
    assert.match(css, /mskba-venue-map-card__media-loading/);
    assert.match(chooser, /const previewResponses=new Map\(\)/);
    assert.match(chooser, /const previewCards=new Map\(\)/);
    assert.match(chooser, /render\(item,Boolean\(item\.preview_url&&!item\.image_url\)\)/);
    assert.match(chooser, /if\(cached\?\.details\)/);
    assert.match(chooser, /let promise=cached\?\.promise/);
    assert.match(chooser, /preview\.replaceChildren\(entry\.node\)/);
    assert.match(chooser, /\.catch\(\(\)=>render\(item,false\)\)/);
});

import './venue-rental-search.test.mjs';

test('rental preference marks one or several days but all nine remain navigable', async () => {
    const dayValues = Array.from({length:9}, (_, i) => '2026-11-' + (15+i));
    const page = mountDayBooking({calendarDays:dayValues});
    const settle = () => new Promise(resolve => setImmediate(resolve));
    const openSearch = (from, to) => page.dialog.listeners['mskba:rental-search']({detail:{
        criteria:{from,to,fromTime:'20:00',toTime:'23:59'},
        courts:[{id:13,name:'Зал 1'}],courtId:13,date:from,note:'Исходная сводка',
        filterSlots:data=>data.slots,eligibleSlots:data=>data.slots,groupSlots:groupDayBookingSlots,back:()=>{},
    }});
    const markers = () => page.dates.map(button=>button.parentElement.dataset.rentalPreference ?? null);
    openSearch(dayValues[2],dayValues[3]);
    await settle();
    assert.deepEqual(markers(),[null,null,'first','last',null,null,null,null,null]);
    assert.equal(page.dates.every(button=>!button.parentElement.hidden),true);
    assert.equal(page.previous.disabled,false);
    page.previous.click();
    await settle();
    assert.equal(page.requests.at(-1).searchParams.get('date'),dayValues[1]);
    assert.match(page.searchNote.textContent,/другой день/);
    assert.ok(page.grid.children.length);
    page.dates[8].click();
    await settle();
    assert.equal(page.requests.at(-1).searchParams.get('date'),dayValues[8]);
    assert.equal(page.next.disabled,true);
    assert.equal(page.dates[8].attributes['aria-pressed'],'true');
    assert.deepEqual(markers(),[null,null,'first','last',null,null,null,null,null]);
    page.dates[2].click();
    await settle();
    assert.equal(page.searchNote.textContent,'Исходная сводка');
    page.dialog.close();
    assert.deepEqual(markers(),Array(9).fill(null));
    openSearch(dayValues[4],dayValues[4]);
    await settle();
    assert.deepEqual(markers(),[null,null,null,null,'single',null,null,null,null]);
    page.next.click();
    await settle();
    assert.equal(page.requests.at(-1).searchParams.get('date'),dayValues[5]);
    assert.equal(markers()[4],'single');
});

test('rental search shows unverified and unavailable intervals as disabled calendar cells', async () => {
    const page = mountDayBooking({customSlots:[
        {starts_at:'18:00',ends_at:'18:30',status:'unknown',selectable:false,price_minor:null},
        {starts_at:'18:30',ends_at:'19:00',status:'unavailable',selectable:false,price_minor:null},
    ]});
    const detail = {criteria:{from:'2026-11-15',to:'2026-11-16'},courts:[{id:13,name:'Зал 1'}],
        courtId:13,date:'2026-11-15',note:'Нет подтверждённых данных',
        filterSlots:data=>data.slots,eligibleSlots:()=>[],groupSlots:groupDayBookingSlots,back:()=>{}};
    page.dialog.listeners['mskba:rental-search']({detail});
    await new Promise(resolve=>setImmediate(resolve));
    assert.deepEqual(page.grid.children.map(cell=>cell.dataset.slotStatus),['unknown','unavailable']);
    assert.ok(page.grid.children.every(cell=>cell.disabled && cell.dataset.slotIndex===undefined));
    assert.equal(page.bookingButton.disabled,true);
    assert.match(page.status.textContent,/нет подтверждённого расписания/);
    const stalePage = mountDayBooking({stale:true});
    stalePage.dialog.listeners['mskba:rental-search']({detail});
    await new Promise(resolve=>setImmediate(resolve));
    assert.equal(stalePage.grid.children.length,2);
    assert.ok(stalePage.grid.children.every(cell=>cell.dataset.slotStatus==='unknown'&&cell.disabled));
    assert.equal(stalePage.bookingButton.disabled,true);
});

test('upcoming venue card opens existing event detail with shared chat before page navigation', () => {
    const page = readFileSync(new URL('../../resources/themes/mskba_app/views/pages/venues/show.blade.php', import.meta.url), 'utf8');
    const popup = readFileSync(new URL('../../resources/views/components/venue-play-funnel.blade.php', import.meta.url), 'utf8');
    const js = readFileSync(new URL('../../resources/themes/mskba_app/js/venue-play-funnel.js', import.meta.url), 'utf8');
    assert.match(page, /data-venue-play-open-event="{{ \$planned->id }}"/);
    assert.match(page, /'venuePlayDirectEvents' => \$plannedVenueEvents/);
    assert.doesNotMatch(page, /аренда ещё не оформлена|Площадка для них пока не забронирована|Открытые мероприятия, на которые можно записаться/);
    assert.match(popup, /concat\(\$venuePlayDirectEvents/);
    assert.match(popup, /@foreach\(\$venuePlayDetailEvents as \$upcoming\)/);
    assert.match(popup, /data-venue-play-event-link/);
    assert.match(js, /direct\.dataset\.venuePlayOpenEvent/);
    assert.match(js, /if\(!getPane\(id\)\)return;/);
    assert.match(js, /if\(!dialog\.open\)dialog\.showModal\(\);\s*showEvent\(id\);/);
});
