import test from 'node:test';
import assert from 'node:assert/strict';
import { observeChatResume } from '../../resources/js/backoffice-chat-resume.js';

test('operator chat refreshes after returning to a background tab, but not while hidden', () => {
    const doc = new EventTarget();
    const win = new EventTarget();
    doc.hidden = true;
    let refreshed = 0;
    const dispose = observeChatResume(() => { refreshed += 1; }, doc, win);

    doc.dispatchEvent(new Event('visibilitychange'));
    win.dispatchEvent(new Event('focus'));
    assert.equal(refreshed, 0);

    doc.hidden = false;
    doc.dispatchEvent(new Event('visibilitychange'));
    assert.equal(refreshed, 1);

    doc.hidden = true;
    doc.dispatchEvent(new Event('visibilitychange'));
    assert.equal(refreshed, 1);
    doc.hidden = false;
    win.dispatchEvent(new Event('focus'));
    assert.equal(refreshed, 2);

    dispose();
    doc.dispatchEvent(new Event('visibilitychange'));
    win.dispatchEvent(new Event('focus'));
    assert.equal(refreshed, 2);
});
