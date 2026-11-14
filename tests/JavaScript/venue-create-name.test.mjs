import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const source = readFileSync(
    new URL('../../resources/themes/mskba_app/js/venue-create-name.js', import.meta.url), 'utf8',
);
function mount(initialName = '') {
    const listeners = {};
    const inputListeners = {};
    const name = {
        value: initialName, maxLength: 255,
        addEventListener(type, handler) { inputListeners[type] = handler; },
    };
    const form = {
        querySelector(selector) { return selector === '[name="name"]' ? name : null; },
        addEventListener(type, handler) { listeners[type] = handler; },
    };
    runInNewContext(source, { document: { querySelectorAll() { return [form]; } } });
    return {
        name,
        choose(street, building) {
            listeners['address-suggest:selected']({ detail: { street, building } });
        },
        type(value) {
            name.value = value;
            inputListeners.input();
        },
    };
}
test('suggested address fills venue name from street and house', () => {
    const page = mount();
    page.choose('Керамический проезд', '49Б');
    assert.equal(page.name.value, 'Керамический проезд, 49Б');
    page.choose('Улица Строителей', '10');
    assert.equal(page.name.value, 'Улица Строителей, 10');
});
test('manual name is preserved, including a name filled before address selection', () => {
    const previous = mount('Баскетбольный корт у школы');
    previous.choose('Улица Ленина', '1');
    assert.equal(previous.name.value, 'Баскетбольный корт у школы');
    const page = mount();
    page.choose('Керамический проезд', '49Б');
    page.type('БК Керамика');
    page.choose('Керамический проезд', '51');
    assert.equal(page.name.value, 'БК Керамика');
});
test('incomplete address does not fill the title', () => {
    const page = mount();
    page.choose('Керамический проезд', '');
    assert.equal(page.name.value, '');
});
