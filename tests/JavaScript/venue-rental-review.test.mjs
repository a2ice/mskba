import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const script = readFileSync(
    new URL('../../resources/themes/mskba_app/js/venue-rental-review.js', import.meta.url), 'utf8',
).replace(/^import\s*\{[^}]+\}\s*from\s*['"][^'"]+['"];\s*/m, '');

function element() {
    return {
        dataset: {}, listeners: {}, textContent: '', value: '',
        classList: { toggle() {} }, hidden: false, open: false,
        addEventListener(type, cb) { this.listeners[type] = cb; },
        setAttribute() {},
        querySelector() { return null; },
        querySelectorAll() { return []; },
        showModal() { this.open = true; },
        close() { this.open = false; this.listeners.close?.(); },
    };
}

async function mount({responses = [{available: true}, {available: false, reason: 'unavailable'}]} = {}) {
    const root = element();
    root.dataset = {rentalVenueId: '26', rentalCurrentCourt: '13', rentalOriginalDate: '2026-11-15'};
    const form = element();
    const court = element();
    court.value = '13';
    const first = {value: '13', dataset: {min: '60', max: '180', step: '30', whole: '1', halves: '0'}};
    const second = {value: '99', dataset: {min: '60', max: '180', step: '30', whole: '1', halves: '0'}};
    Object.defineProperty(court, 'selectedOptions', {get: () => [court.value === '13' ? first : second]});
    const start = element(); start.value = '2026-11-15T21:00';
    const duration = element(); duration.value = '60';
    const scope = element();
    scope.value = 'whole';
    const scopeOptions = [
        {value: 'whole', dataset: {scope: 'whole'}, disabled: false},
        {value: 'half_a', dataset: {scope: 'half'}, disabled: false},
    ];
    scope.options = scopeOptions;
    Object.defineProperty(scope, 'selectedOptions', {get: () => scopeOptions.filter(x => x.value === scope.value)});
    scope.querySelectorAll = () => scopeOptions;
    const calculate = element();
    const choose = element();
    const formItems = {
        '[data-rental-court]': court, '[data-rental-starts]': start,
        '[data-rental-duration]': duration, '[data-rental-scope]': scope,
        '[data-rental-submit]': calculate, '[data-rental-open-calendar]': choose,
    };
    form.querySelector = key => formItems[key] || null;

    const monitor = element();
    monitor.dataset = {
        quoteExpiration: '2099-01-01T00:00:00+00:00',
        quoteStatusUrl: 'https://dev.mskba.ru/venues/26/rental/quote/status',
        quoteId: '88888888-8888-4888-8888-888888888888',
    };
    const send = element(); send.tagName = 'BUTTON';
    const sendForm = element();
    const message = element();
    monitor.querySelector = key => ({
        '[data-rental-send-button]': send,
        '[data-rental-send-form]': sendForm,
        '[data-rental-live-message]': message,
    })[key] || null;

    root.querySelector = key => ({
        '[data-venue-rental-form]': form,
        '[data-rental-quote-monitor]': monitor,
    })[key] || null;

    const calendar = element();
    calendar.dispatchEvent = event => {
        calendar.lastSelection = event.detail;
    };
    const conflict = element();
    const conflictText = element();
    const conflictChoose = element();
    const conflictClose = element();
    conflict.querySelector = key => ({
        '[data-rental-conflict-message]': conflictText,
        '[data-rental-conflict-choose]': conflictChoose,
        '[data-rental-conflict-close]': conflictClose,
    })[key] || null;
    const document = {
        hidden: false,
        listeners: {},
        querySelector: key => ({
            '[data-rental-quote-page]': root,
            '[data-venue-day-dialog]': calendar,
            '[data-rental-conflict-dialog]': conflict,
        })[key] || null,
        addEventListener(type, cb) {this.listeners[type] = cb;},
        removeEventListener() {},
    };
    let socketHandler;
    const requests = [];
    const subscribePublic = (_channel, _event, cb) => {socketHandler = cb; return () => {};};
    const window = {
        location: {href: 'https://dev.mskba.ru/venues/26/rental/quote'},
        listeners: {},
        addEventListener(type, cb) {this.listeners[type] = cb;},
        removeEventListener() {},
        setInterval(cb) {this.tick = cb; return 1;},
        clearInterval() {},
    };
    let responseIndex = 0;
    const fetch = async url => {
        requests.push(new URL(url));
        return {ok: true, json: async () => responses[Math.min(responseIndex++, responses.length - 1)]};
    };
    class CustomEvent {constructor(type, options) {this.type = type;this.detail = options.detail;}}
    runInNewContext(script, {document, window, fetch, URL, Date, CustomEvent, subscribePublic});
    await new Promise(resolve => setImmediate(resolve));
    return {root, form, court, send, sendForm, message, calendar, conflict, conflictText,
        conflictChoose, socketHandler, requests, window};
}

test('WebSocket notice makes the occupied quote unsendable and opens the new time dialog', async () => {
    const page = await mount();
    assert.equal(page.send.textContent, 'Отправить заявку');
    page.socketHandler({court_id: 13, date: '2026-11-15'});
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(page.send.textContent, 'Выбрать новое время');
    assert.equal(page.message.hidden, false);
    const event = {preventDefaultCalled: false, preventDefault() {this.preventDefaultCalled = true;}};
    page.sendForm.listeners.submit(event);
    assert.equal(event.preventDefaultCalled, true);
    assert.equal(page.conflict.open, true);
    assert.match(page.conflictText.textContent, /заняли/);
    page.conflictChoose.listeners.click();
    assert.equal(page.conflict.open, false);
    assert.equal(page.calendar.lastSelection.courtId, 13);
});

test('Court selector uses the same calendar, and cancelling restores the original quote', async () => {
    const page = await mount({responses: [{available: true}]});
    page.court.value = '99';
    page.court.listeners.change();
    assert.equal(page.calendar.lastSelection.courtId, 99);
    assert.equal(page.calendar.lastSelection.date, '2026-11-15');
    page.calendar.listeners.close();
    assert.equal(page.court.value, '13');
});

test('Quote status requests contain a capability ID, not an applicant or payment record', async () => {
    const page = await mount({responses: [{available: true}]});
    assert.equal(page.requests.length, 1);
    assert.equal(page.requests[0].searchParams.get('quote_id'),
        '88888888-8888-4888-8888-888888888888');
    assert.equal(page.send.textContent, 'Отправить заявку');
});
