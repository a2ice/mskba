import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/themes/mskba_app/js/account-contacts-delete.js', import.meta.url), 'utf8');

function makeHarness() {
    const registered = new Map();
    const dialogEvents = new Map();
    const cancelButtons = Array.from({length: 2}, () => ({ handler: null, addEventListener(event, fn) { this.handler = fn; } }));
    const cancelFocus = { focused: false, focus() { this.focused = true; } };
    const target = { textContent: '' };
    const confirm = { disabled: false, handler: null, addEventListener(event, fn) { this.handler = fn; } };
    const dialog = {
        open: false,
        showModal() { this.open = true; },
        close() { this.open = false; dialogEvents.get('close')?.(); },
        querySelector(selector) {
            if (selector === '[data-contact-delete-target]') return target;
            if (selector === '[data-contact-delete-confirm]') return confirm;
            if (selector.includes('__delete-footer')) return cancelFocus;
            return null;
        },
        querySelectorAll() { return cancelButtons; },
        addEventListener(name, callback) { dialogEvents.set(name, callback); },
    };
    const document = {
        body: { style: {overflow: ''} },
        querySelector: () => dialog,
        addEventListener(name, fn) { registered.set(name, fn); },
    };
    runInNewContext(source, { document });
    const form = (label) => {
        const opener = { isConnected: true, focused: false, focus() { this.focused = true; } };
        const instance = {
            isConnected: true, dataset: {contactLabel: label}, submits: 0,
            matches: (selector) => selector === '[data-contact-delete-form]',
            querySelector: () => opener,
            submit() { this.submits++; },
        };
        return {instance, opener};
    };
    return {
        dialog, target, confirm, cancelButtons, document, form,
        trigger(instance, opener) {
            let prevented = false;
            registered.get('submit')({
                target: instance, submitter: opener,
                preventDefault() { prevented = true; },
            });
            assert.equal(prevented, true);
        },
    };
}

test('delete click prompts with exact contact and does not submit immediately', () => {
    const h = makeHarness();
    const {instance, opener} = h.form('Email: first@example.test');
    h.trigger(instance, opener);
    assert.equal(instance.submits, 0);
    assert.equal(h.dialog.open, true);
    assert.equal(h.target.textContent, 'Email: first@example.test');
    assert.equal(h.document.body.style.overflow, 'hidden');
});

test('cancel closes dialog, restores scroll and does not delete', () => {
    const h = makeHarness();
    const {instance, opener} = h.form('Email: first@example.test');
    h.trigger(instance, opener);
    h.cancelButtons[1].handler();
    assert.equal(h.dialog.open, false);
    assert.equal(instance.submits, 0);
    assert.equal(opener.focused, true);
    assert.equal(h.document.body.style.overflow, '');
});

test('escape (native cancel) closes without submitting', () => {
    const h = makeHarness();
    const {instance, opener} = h.form('Email: first@example.test');
    h.trigger(instance, opener);
    h.dialog.close();
    assert.equal(instance.submits, 0);
    assert.equal(opener.focused, true);
});

test('confirmed deletion submits only selected contact exactly once', () => {
    const h = makeHarness();
    const first = h.form('Email: first@example.test');
    const second = h.form('Email: second@example.test');
    h.trigger(first.instance, first.opener);
    h.cancelButtons[1].handler();
    h.trigger(second.instance, second.opener);
    assert.equal(h.target.textContent, 'Email: second@example.test');
    h.confirm.handler();
    h.confirm.handler();
    assert.equal(first.instance.submits, 0);
    assert.equal(second.instance.submits, 1);
    assert.equal(h.confirm.disabled, true);
});

test('detached contact after AJAX refresh cannot be deleted', () => {
    const h = makeHarness();
    const first = h.form('Email: first@example.test');
    h.trigger(first.instance, first.opener);
    first.instance.isConnected = false;
    h.confirm.handler();
    assert.equal(first.instance.submits, 0);
    assert.equal(h.dialog.open, false);
});
