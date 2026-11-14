import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const script = readFileSync(
    new URL('../../resources/themes/mskba_app/js/venue-create-validation.js', import.meta.url), 'utf8',
);
function mount() {
    const listeners = {};
    const nodes = new Map();
    const host = {
        append(node) { nodes.set(node.id, node); },
        after(node) { nodes.set(node.id, node); },
    };
    function field(name, tagName = 'INPUT') {
        const attrs = {};
        const item = {
            name, tagName, dataset: {}, validity: {valueMissing: true},
            validationMessage: '', minLength: 3, focused: false,
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
    const status = { textContent: '' };
    const elements = [address, name, type, court];
    const form = {
        elements,
        addEventListener(type, fn) { listeners[type] = fn; },
        querySelector(selector) {
            if (selector === '[data-address-suggest-input]') return address;
            if (selector === '[data-venue-create-status]') return status;
            if (selector === '[data-address-selected]') return selected;
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
    runInNewContext(script, {document, requestAnimationFrame: fn => fn()});
    const event = (type, target, detail) => {
        const e = {
            target, detail, prevented: false, stopped: false,
            preventDefault() { this.prevented = true; },
            stopImmediatePropagation() { this.stopped = true; },
        };
        listeners[type](e);
        return e;
    };
    return {event, elements, address, name, type, court, selected, nodes, status};
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
