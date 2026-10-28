import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/themes/mskba_app/js/contracts-workspace.js', import.meta.url), 'utf8');
test('native venue contract dialog opens only on its button and closes on close button', () => {
    const listeners = {};
    const calls = [];
    const dialog = { showModal: () => calls.push('open'), close: () => calls.push('close') };
    runInNewContext(source, {
        document: {
            addEventListener: (event, fn) => {
                (listeners[event] ||= []).push(fn);
            },
            getElementById: id => id === 'venue-contract-7' ? dialog : null,
        },
    });
    const button = { getAttribute: () => 'venue-contract-7' };
    listeners.click[0]({ target: { closest: selector => selector === '[data-contract-dialog-open]' ? button : null } });
    assert.deepEqual(calls, ['open']);
    listeners.click[0]({ target: {
        closest: selector => selector === '[data-contract-dialog-close]' ? { closest: () => dialog } : null,
    } });
    assert.deepEqual(calls, ['open', 'close']);
});
