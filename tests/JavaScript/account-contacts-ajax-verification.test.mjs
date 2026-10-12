import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const code = readFileSync(new URL('../../resources/themes/mskba_app/js/account-contacts-resend.js', import.meta.url), 'utf8')
    .replace(/^import .*;\n/gm, '');

function harness({ response = { ok: true, status: 200, data: { seconds_left: 60 } }, icon = false, failRefresh = false } = {}) {
    const events = new Map();
    const button = {
        disabled: false, innerHTML: icon ? '<svg></svg>' : '<span>Подтвердить</span>',
        dataset: {}, classList: { contains: () => icon },
        attributes: {},
        setAttribute(name, value) { this.attributes[name] = value; },
        removeAttribute(name) { delete this.attributes[name]; },
    };
    const count = { textContent: '0' };
    const hint = { hidden: true, querySelector: () => count };
    const error = { hidden: true, textContent: '' };
    const row = { querySelector(selector) {
        return selector === '[data-contact-resend-hint]' ? hint : error;
    } };
    const form = {
        isConnected: true, dataset: { cooldownSeconds: '0' }, action: '/account/contacts/123/verification',
        closest: () => row,
        querySelector: () => button,
        addEventListener(event, handler) { events.set(event, handler); },
    };
    let requests = [], refreshes = 0;
    const scope = {
        document: {
            querySelector(selector) { return selector === 'meta[name="csrf-token"]' ? { content: 'test-csrf' } : null; },
            querySelectorAll: () => [form],
            addEventListener() {},
        },
        createContactResendCooldown(callback) {
            let remaining = 0;
            return {
                set(seconds) { remaining = Number(seconds); callback(remaining); },
                get secondsLeft() { return remaining; },
                dispose() {},
            };
        },
        async refreshAccountContactsList() {
            refreshes++;
            if (failRefresh) throw new Error('refresh unavailable');
        },
        async fetch(url, options) {
            requests.push({ url, options });
            return { ok: response.ok, status: response.status, json: async () => response.data };
        },
        Map, Number, Math, Object, Event: class {},
    };
    runInNewContext(code, scope);
    return {
        button, count, hint, error, requests, refreshes: () => refreshes,
        async submit() {
            let prevented = false;
            await events.get('submit')({ preventDefault() { prevented = true; } });
            assert.equal(prevented, true);
        },
    };
}

test('first Verify action sends JSON asynchronously, then refreshes the contact row', async () => {
    const h = harness();
    assert.match(h.button.innerHTML, /Подтвердить/);
    await h.submit();
    assert.equal(h.requests.length, 1);
    assert.equal(h.requests[0].options.method, 'POST');
    assert.equal(h.requests[0].options.headers.Accept, 'application/json');
    assert.equal(h.requests[0].options.headers['X-CSRF-TOKEN'], 'test-csrf');
    assert.equal(h.refreshes(), 1);
    assert.equal(h.count.textContent, '60');
    assert.equal(h.hint.hidden, false);
    assert.equal(h.error.hidden, true);
    assert.equal(h.button.disabled, true);
});

test('SMTP failure stays below the contact and restores the initial button', async () => {
    const h = harness({ response: { ok: false, status: 503, data: { message: 'Почта пока недоступна' } } });
    await h.submit();
    assert.equal(h.refreshes(), 0);
    assert.equal(h.error.hidden, false);
    assert.equal(h.error.textContent, 'Почта пока недоступна');
    assert.equal(h.button.disabled, false);
    assert.match(h.button.innerHTML, /Подтвердить/);
});

test('429 uses server countdown and refreshes the row without duplicate error', async () => {
    const h = harness({ response: { ok: false, status: 429, data: { seconds_left: 34 } }, icon: true });
    await h.submit();
    assert.equal(h.refreshes(), 1);
    assert.equal(h.count.textContent, '34');
    assert.equal(h.error.hidden, true);
    assert.equal(h.button.disabled, true);
});

test('accepted email with failed fragment refresh explains how to enter code', async () => {
    const h = harness({ failRefresh: true });
    await h.submit();
    assert.equal(h.error.hidden, false);
    assert.match(h.error.textContent, /Код отправлен/);
});
