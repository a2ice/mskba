import test from 'node:test';
import assert from 'node:assert/strict';
import { initCookieConsent } from '../../resources/themes/mskba_app/js/cookie-consent.js';

function setup({ counterId = '12345678', initialHidden = false, response, failure } = {}) {
    const listeners = {};
    const toasts = [];
    const appended = [];
    const buttons = [{ value: 'rejected', disabled: false }, { value: 'accepted', disabled: false }];
    const error = { hidden: true };
    const form = {
        action: 'https://mskba.ru/cookie-consent',
        addEventListener: (type, fn) => { listeners[type] = fn; },
        querySelectorAll: (selector) => selector === 'button' ? buttons : [],
        querySelector: (selector) => selector === 'input[name="_token"]' ? { value: 'csrf' } : null,
    };
    const banner = {
        hidden: initialHidden,
        dataset: { metrikaCounterId: counterId },
        querySelector: (selector) => ({
            '[data-cookie-consent-form]': form,
            '[data-cookie-consent-error]': error,
        })[selector] ?? null,
    };
    const doc = {
        referrer: '',
        head: { append: (el) => { appended.push(el); } },
        querySelector: (selector) => selector === '[data-cookie-consent-banner]' ? banner : null,
        querySelectorAll: () => [],
        createElement: (name) => ({ tagName: name }),
    };
    const host = {
        location: { href: 'https://mskba.ru/venues' },
        dispatchEvent: (event) => toasts.push(event.detail),
        // Intentionally do not define location.reload: consent must not navigate.
    };
    const requests = [];
    const fetchRequest = async (url, init) => {
        requests.push({ url, init });
        if (failure) throw failure;
        return response || { ok: true, json: async () => ({ analytics: JSON.parse(init.body).analytics }) };
    };
    initCookieConsent(doc, host, fetchRequest);
    const submit = async (choice) => {
        const event = {
            submitter: buttons.find((button) => button.value === choice),
            defaultPrevented: false,
            preventDefault() { this.defaultPrevented = true; },
        };
        await listeners.submit(event);
        return event;
    };
    return { submit, requests, banner, error, buttons, host, toasts, appended };
}

test('accept all persists with JSON POST, hides banner, displays toast and starts analytics', async () => {
    const app = setup();
    const event = await app.submit('accepted');
    assert.equal(event.defaultPrevented, true);
    assert.equal(app.requests.length, 1);
    assert.deepEqual(JSON.parse(app.requests[0].init.body), { analytics: 'accepted' });
    assert.equal(app.requests[0].init.headers['X-CSRF-TOKEN'], 'csrf');
    assert.equal(app.banner.hidden, true);
    assert.equal(app.banner.dataset.cookieConsentState, 'accepted');
    assert.equal(app.error.hidden, true);
    assert.deepEqual(app.toasts, [{ message: 'Все cookie приняты', failed: false }]);
    assert.equal(app.host.disableYaCounter12345678, false);
    assert.equal(app.appended[0].src, 'https://mc.yandex.ru/metrika/tag.js');
    assert.equal(app.host.ym.a[0][0], 12345678);
    assert.equal(app.host.ym.a[0][1], 'init');
    assert.deepEqual(app.buttons.map((button) => button.disabled), [false, false]);
});

test('necessary cookies only persists without reload, skips analytics, gives feedback', async () => {
    const app = setup();
    await app.submit('rejected');
    assert.equal(app.banner.hidden, true);
    assert.equal(app.banner.dataset.cookieConsentState, 'rejected');
    assert.equal(app.host.disableYaCounter12345678, true);
    assert.equal(app.appended.length, 0);
    assert.deepEqual(app.toasts, [{ message: 'Выбраны только необходимые cookie', failed: false }]);
});

test('server failure leaves banner visible, shows error toast and enables retry', async () => {
    const app = setup({ response: { ok: false } });
    await app.submit('accepted');
    assert.equal(app.banner.hidden, false);
    assert.equal(app.error.hidden, false);
    assert.deepEqual(app.buttons.map((button) => button.disabled), [false, false]);
    assert.equal(app.appended.length, 0);
    assert.deepEqual(app.toasts, [{
        message: 'Не удалось сохранить настройки cookie. Попробуйте ещё раз.',
        failed: true,
    }]);
});

test('network error and malformed success response never conceal banner', async () => {
    for (const options of [
        { failure: new Error('offline') },
        { response: { ok: true, json: async () => ({ analytics: 'rejected' }) } },
    ]) {
        const app = setup(options);
        await app.submit('accepted');
        assert.equal(app.banner.hidden, false);
        assert.equal(app.error.hidden, false);
        assert.equal(app.appended.length, 0);
        assert.equal(app.toasts[0].failed, true);
    }
});

test('revoke an existing choice without reinitializing analytics', async () => {
    const app = setup({ initialHidden: true });
    app.host.ym = () => {};
    await app.submit('rejected');
    assert.equal(app.host.disableYaCounter12345678, true);
    assert.equal(app.banner.hidden, true);
    assert.equal(app.appended.length, 0);
});

test('nonproduction host with no counter id never loads analytics', async () => {
    const app = setup({ counterId: '' });
    await app.submit('accepted');
    assert.equal(app.banner.hidden, true);
    assert.equal(app.appended.length, 0);
});
