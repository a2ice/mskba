import test from 'node:test';
import assert from 'node:assert/strict';
import { markVisitRefreshFailed } from '../../resources/js/venue-visit-schedule-state.js';

function scope(id, state) {
    const spinner = { hidden: state !== 'updating' };
    const error = { hidden: state !== 'failed' };
    const label = { textContent: state };
    const nodes = {
        '[data-venue-refresh-spinner]': spinner,
        '[data-venue-refresh-error]': error,
        '[data-venue-refresh-label]': label,
    };
    const message = {
        dataset: { refreshState: state, refreshObserved: '27.11 10:30' },
        querySelector: key => nodes[key],
    };
    const days = { busy: 'true', setAttribute(key, value) { this.busy = value; } };
    const button = { disabled: true, marker: true, removeAttribute() { this.marker = false; } };
    const otherDisabledButton = { disabled: true };
    return {
        dataset: { venueRefreshScope: id },
        querySelector: () => message,
        querySelectorAll: key => key === '[data-venue-refresh-days]' ? [days] : [button],
        message, spinner, error, label, days, button, otherDisabledButton,
    };
}

test('timeout switches only pending zones to error and unlocks refresh-disabled days', () => {
    const pending = scope('1', 'updating');
    const unrelated = scope('2', 'cached');
    markVisitRefreshFailed({ querySelectorAll: () => [pending, unrelated] }, new Set(['1']));
    assert.equal(pending.message.dataset.refreshState, 'failed');
    assert.equal(pending.spinner.hidden, true);
    assert.equal(pending.error.hidden, false);
    assert.equal(pending.label.textContent, 'Не удалось обновить');
    assert.match(pending.message.title, /27\.11 10:30/);
    assert.equal(pending.button.disabled, false);
    assert.equal(pending.button.marker, false);
    assert.equal(pending.days.busy, 'false');
    assert.equal(pending.otherDisabledButton.disabled, true);
    assert.equal(unrelated.message.dataset.refreshState, 'cached');
    assert.equal(unrelated.button.disabled, true);
});

test('expired pending marker renders stale snapshot as a failed refresh', () => {
    const expired = scope('4', 'stale');
    markVisitRefreshFailed({ querySelectorAll: () => [expired] }, new Set(['4']));
    assert.equal(expired.message.dataset.refreshState, 'failed');
    assert.equal(expired.error.hidden, false);
    assert.equal(expired.button.disabled, false);
});
