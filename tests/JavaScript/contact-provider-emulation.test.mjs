import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const code = readFileSync(new URL('../../resources/themes/mskba_app/js/contact-provider-emulation.js', import.meta.url), 'utf8')
    .replace('export function createContactProviderEmulation', 'function createContactProviderEmulation')
    + '\nthis.createContactProviderEmulation = createContactProviderEmulation;\n';

function setup({ response = { message: 'Эмуляция успешна' }, error = null, enabled = true } = {}) {
    const option = { value: 'success' };
    const button = { disabled: false, textContent: '', handler: null,
        addEventListener(_event, callback) { this.handler = callback; } };
    const step = { querySelector(selector) {
        return selector === '[data-provider-emulation-outcome]' ? option : button;
    } };
    const dialog = { open: true, querySelector: () => step };
    const root = { dataset: enabled ? {emulateUrl:'/account/contacts/provider-emulation'} : {} };
    const calls = [], errors = [], successes = [], busy = [];
    const state = {};
    runInNewContext(code, state);
    const simulator = state.createContactProviderEmulation({
        root, dialog,
        async post(url, data) {
            calls.push({ url, data });
            if (error) throw new Error(error);
            return response;
        },
        async onSuccess(data) { successes.push(data); },
        onError(message) { errors.push(message); },
        onBusy(state) { busy.push(state); },
    });
    return { option, button, calls, errors, successes, busy, simulator,
        run: () => button.handler() };
}

test('success uses selected Telegram scenario and trusts only server result', async () => {
    const h=setup(); h.simulator.choose('telegram');
    await h.run();
    assert.equal(h.calls.length,1);
    assert.deepEqual(Object.fromEntries(Object.entries(h.calls[0].data)), { provider:'telegram', outcome:'success' });
    assert.equal(h.successes[0].message,'Эмуляция успешна');
    assert.deepEqual(h.busy,[true,false]);
    assert.equal(h.button.disabled,false);
});

test('simulated VK failure displays server error and never calls success', async () => {
    const h=setup({error:'Сервис временно недоступен'});
    h.simulator.choose('vk');
    h.option.value='unavailable';
    await h.run();
    assert.equal(h.calls[0].data.provider,'vk');
    assert.equal(h.calls[0].data.outcome,'unavailable');
    assert.deepEqual(h.errors,['','Сервис временно недоступен']);
    assert.equal(h.successes.length,0);
});

test('disabled simulation never shows the active action', () => {
    const h=setup({enabled:false});
    assert.equal(h.simulator.available,false);
    assert.equal(h.button.handler,null);
});
