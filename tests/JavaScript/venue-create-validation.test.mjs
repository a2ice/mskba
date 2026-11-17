import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const script = readFileSync(
    new URL('../../resources/themes/mskba_app/js/venue-create-validation.js', import.meta.url), 'utf8',
);
const nameAutofillScript = readFileSync(
    new URL('../../resources/themes/mskba_app/js/venue-create-name.js', import.meta.url), 'utf8',
);
function mount({ withAutofill = false } = {}) {
    const listeners = {};
    const nodes = new Map();
    const host = {
        append(node) { nodes.set(node.id, node); },
        after(node) { nodes.set(node.id, node); },
    };
    function field(name, tagName = 'INPUT') {
        const attrs = {};
        const fieldListeners = {};
        const item = {
            name, tagName, value: '', maxLength: 255, dataset: {}, validity: {valueMissing: true},
            validationMessage: '', minLength: 3, focused: false,
            setCustomValidity(message) {
                this.validationMessage = message;
                this.validity.customError = message.length > 0;
            },
            addEventListener(type, handler) { fieldListeners[type] = handler; },
            fire(type) { fieldListeners[type]?.({target: this}); },
            dispatchEvent(event) {
                if (event.bubbles) (listeners[event.type] || []).forEach(callback => callback({target: item}));
                return true;
            },
            closest() { return host; },
            setAttribute(key, value) { attrs[key] = value; },
            getAttribute(key) { return attrs[key] ?? null; },
            removeAttribute(key) { delete attrs[key]; },
            matches() { return true; },
            focus() { item.focused = true; },
            scrollIntoView() { item.scrolled = true; },
            get attrs() { return attrs; },
        };
        return item;
    }
    const address = field('location[raw_address]');
    address.dataset.validationLabel = 'Адрес площадки';
    const name = field('name');
    const type = field('type', 'SELECT');
    const court = field('initial_courts[0][environment]', 'SELECT');
    const selected = { value: '' };
    const clear = {listeners: {}, addEventListener(type, cb) {this.listeners[type] = cb;},
        click() {this.listeners.click?.();}};
    const status = { textContent: '' };
    const elements = [address, name, type, court];
    const form = {
        elements,
        addEventListener(type, fn) { (listeners[type] ||= []).push(fn); },
        querySelector(selector) {
            if (selector === '[data-address-suggest-input]') return address;
            if (selector === '[data-venue-create-status]') return status;
            if (selector === '[data-address-selected]') return selected;
            if (selector === '[data-address-clear]') return clear;
            if (selector === '[name="name"]') return name;
            if (selector === '[aria-invalid="true"]') return elements.find(item => item.attrs['aria-invalid'] === 'true') || null;
            return null;
        },
    };
    const document = {
        querySelectorAll() { return [form]; },
        getElementById(id) { return nodes.get(id) || null; },
        createElement() {
            return {
                dataset: {}, className:'', textContent: '', id: '',
                setAttribute(key, value) { this[key] = value; },
                remove() { nodes.delete(this.id); },
            };
        },
    };
    const context = {
        document,
        requestAnimationFrame: fn => fn(),
        Event: class {
            constructor(type, options = {}) {
                this.type = type;
                this.bubbles = Boolean(options.bubbles);
            }
        },
    };
    runInNewContext(script, context);
    if (withAutofill) runInNewContext(nameAutofillScript, context);
    const event = (type, target, detail) => {
        const e = {
            target, detail, prevented: false, stopped: false,
            preventDefault() { this.prevented = true; },
            stopImmediatePropagation() { this.stopped = true; },
        };
        for (const callback of listeners[type] || []) callback(e);
        return e;
    };
    return {event, elements, address, name, type, court, selected, clear, nodes, status};
}

test('required fields get red inline errors, and editing clears just the edited error', () => {
    const page = mount();
    const result = page.event('invalid', page.type);
    assert.equal(result.prevented, true);
    assert.equal(page.type.attrs['aria-invalid'], 'true');
    const id = page.type.dataset.venueErrorId;
    assert.match(page.nodes.get(id).textContent, /Выберите значение/);
    assert.equal(page.type.focused, true);
    assert.equal(page.type.scrolled, true);
    page.event('invalid', page.name);
    page.event('change', page.type);
    assert.equal(page.type.attrs['aria-invalid'], undefined);
    assert.equal(page.nodes.has(id), false);
    assert.equal(page.name.attrs['aria-invalid'], 'true');
});

test('typing an address never qualifies as an accepted choice', () => {
    const page = mount();
    const submit = page.event('submit');
    assert.equal(submit.prevented, true);
    assert.equal(submit.stopped, true);
    assert.equal(page.address.attrs['aria-invalid'], 'true');
    assert.match(page.nodes.get(page.address.dataset.venueErrorId).textContent, /Выберите точный адрес/);
    page.event('input', page.address);
    assert.equal(page.address.attrs['aria-invalid'], undefined);
    page.event('submit');
    page.selected.value = '1';
    page.event('address-suggest:selected');
    const accepted = page.event('submit');
    assert.equal(accepted.prevented, false);
    assert.equal(page.address.attrs['aria-invalid'], undefined);
});

test('server 422 errors target the exact court fields and address', () => {
    const page = mount();
    page.event('venue-create:server-errors', null, {
        errors: {
            'initial_courts.0.environment': ['Выберите тип зала'],
            'location.address_selected': ['Выберите адрес из подсказок'],
        },
    });
    assert.equal(page.court.attrs['aria-invalid'], 'true');
    assert.equal(page.address.attrs['aria-invalid'], 'true');
    assert.match(page.nodes.get(page.court.dataset.venueErrorId).textContent, /Выберите тип зала/);
    page.event('change', page.court);
    assert.equal(page.court.attrs['aria-invalid'], undefined);
    assert.equal(page.address.attrs['aria-invalid'], 'true');
});


test('unselected address is invalid together with other required fields even without submit', () => {
    const page = mount();
    assert.match(page.address.validationMessage, /Выберите точный адрес/);
    assert.equal(page.address.validity.customError, true);
    page.address.validity.valueMissing = false; // user typed raw text, not a suggestion
    page.event('invalid', page.address);
    page.event('invalid', page.type);
    page.event('invalid', page.court);
    assert.equal(page.address.attrs['aria-invalid'], 'true');
    assert.equal(page.type.attrs['aria-invalid'], 'true');
    assert.equal(page.court.attrs['aria-invalid'], 'true');
    assert.match(page.nodes.get(page.address.dataset.venueErrorId).textContent, /Выберите точный адрес/);
    assert.equal(page.address.focused, true);
});

test('accepted address becomes valid, and typing or clearing makes it invalid again', () => {
    const page = mount();
    page.selected.value = '1';
    page.event('address-suggest:selected');
    assert.equal(page.address.validationMessage, '');
    assert.equal(page.address.validity.customError, false);

    // A keystroke invalidates selection even before the address module clears its hidden field.
    page.address.fire('input');
    assert.match(page.address.validationMessage, /Выберите точный адрес/);
    page.selected.value = '';
    page.event('invalid', page.address);
    assert.equal(page.address.attrs['aria-invalid'], 'true');
    page.selected.value = '1';
    page.event('address-suggest:selected');
    assert.equal(page.address.attrs['aria-invalid'], undefined);
    assert.equal(page.address.validationMessage, '');
    page.clear.click();
    assert.match(page.address.validationMessage, /Выберите точный адрес/);
});

test('app venue and dynamic court selects use the documented UI Kit primitive', () => {
    const createForm = readFileSync(new URL('../../resources/themes/mskba_app/views/pages/venues/partials/create-form.blade.php', import.meta.url), 'utf8');
    const courtRow = readFileSync(new URL('../../resources/views/venues/partials/initial-court-row.blade.php', import.meta.url), 'utf8');
    assert.match(createForm, /class="select-control"[\s\S]*?<select name="type"/);
    assert.match(createForm, /class="select-control"[\s\S]*?<select name="access_type"/);
    for (const key of ['environment', 'hoops_count', 'surface_type']) {
        assert.match(courtRow, new RegExp('class="select-control"[\\s\\S]*?<select name="initial_courts\\[\\{\\{ \\$index \\}\\}\\]\\[' + key + '\\]'));
    }
    assert.match(courtRow, /@if\(\$appThemeSelects\)/);
    assert.match(createForm, /useAppSelects.*true/);
});


test('real validation error clears when address selection programmatically autofills venue name', () => {
    const page = mount({withAutofill: true});
    page.event('invalid', page.name);
    page.event('invalid', page.type);
    const previousErrorId = page.name.dataset.venueErrorId;
    assert.equal(page.name.attrs['aria-invalid'], 'true');
    assert.equal(page.type.attrs['aria-invalid'], 'true');

    page.selected.value = '1';
    page.event('address-suggest:selected', null, {
        street: 'Керамический проезд', building: '49Б',
    });
    assert.equal(page.name.value, 'Керамический проезд, 49Б');
    assert.equal(page.name.attrs['aria-invalid'], undefined);
    assert.equal(page.nodes.has(previousErrorId), false);
    assert.equal(page.type.attrs['aria-invalid'], 'true');

    // Automatic names can still follow later address selections.
    page.event('address-suggest:selected', null, {
        street: 'Керамический проезд', building: '51',
    });
    assert.equal(page.name.value, 'Керамический проезд, 51');

    // Manually edited names are never overwritten.
    page.name.value = 'Корт на улице';
    page.name.fire('input');
    page.event('address-suggest:selected', null, {
        street: 'Керамический проезд', building: '53',
    });
    assert.equal(page.name.value, 'Корт на улице');
});
