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
    const changeEvents = [];
    const name = {
        value: initialName, maxLength: 255,
        addEventListener(type, handler) { inputListeners[type] = handler; },
        dispatchEvent(event) {
            changeEvents.push({ type: event.type, bubbles: event.bubbles });
            if (event.bubbles) listeners[event.type]?.({ target: name });
            return true;
        },
    };
    const form = {
        querySelector(selector) { return selector === '[name="name"]' ? name : null; },
        addEventListener(type, handler) { listeners[type] = handler; },
    };
    runInNewContext(source, {
        document: { querySelectorAll() { return [form]; } },
        Event: class {
            constructor(type, options = {}) {
                this.type = type;
                this.bubbles = Boolean(options.bubbles);
            }
        },
    });
    return {
        name,
        changeEvents,
        onChange(handler) { listeners.change = handler; },
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

test('autofill triggers bubbling change to clear a previous validation error without marking title manual', () => {
    const page = mount();
    let hasValidationError = true;
    page.onChange(({ target }) => {
        if (target === page.name && target.value.trim()) hasValidationError = false;
    });
    page.choose('Керамический проезд', '49Б');
    assert.equal(page.name.value, 'Керамический проезд, 49Б');
    assert.equal(hasValidationError, false);
    assert.deepEqual(page.changeEvents[0], { type: 'change', bubbles: true });

    // A normal input event must not fire for the automatic suggestion.
    // Selecting a second address remains capable of refreshing the suggestion.
    page.choose('Керамический проезд', '51');
    assert.equal(page.name.value, 'Керамический проезд, 51');
    assert.equal(page.changeEvents.length, 2);

    page.type('Баскетбольный двор');
    page.choose('Керамический проезд', '53');
    assert.equal(page.name.value, 'Баскетбольный двор');
    assert.equal(page.changeEvents.length, 2);
});
