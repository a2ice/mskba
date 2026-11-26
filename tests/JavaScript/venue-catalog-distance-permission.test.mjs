import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/themes/mskba_app/js/venue-catalog-distance.js', import.meta.url), 'utf8')
    .replace(/^import .*;\n/m, '');
const tick = () => new Promise(resolve => setImmediate(resolve));

function harness({ state = 'prompt', storage = new Map(), permissions = true, answered = false } = {}) {
    let calls = 0, locationResolve, locationReject, change, option = null;
    const posts = [];
    const listeners = {};
    const button = () => ({ focus() {}, addEventListener() {} });
    const controls = {
        '[data-distance-accept]': button(), '[data-distance-manual]': button(),
        '[data-distance-status]': { hidden: true },
    };
    const dialog = {
        dataset: { locationAvailable: '0', promptAnswered: answered ? '1' : '0', distanceSelected: '0', locationUrl: '/venues/distance-location' },
        open: false, showModal() { this.open = true; }, close() { this.open = false; },
        querySelector(selector) { return controls[selector]; }, addEventListener() {},
    };
    const templateLink = { href: '/venues?sort=distance', cloneNode() { return this; } };
    const menu = {
        querySelector(selector) {
            if (selector === '[data-venue-distance-template]') return { content: { firstElementChild: templateLink } };
            if (selector === '[data-venue-distance-option]') return option;
            if (selector === '.app-catalog-tools__sort-menu') return { append(node) { option = node; } };
            return null;
        },
    };
    const document = {
        addEventListener(name, callback) { listeners[name] = callback; },
        querySelector(selector) {
            if (selector === '[data-venue-distance-prompt]') return dialog;
            if (selector === '#venues-catalog') return { querySelector: () => menu };
            return null;
        },
    };
    const permission = { state, addEventListener(_, callback) { change = callback; } };
    const navigator = permissions ? { permissions: { query: async () => permission } } : {};
    const window = { sessionStorage: { getItem: key => storage.get(key), setItem: (key, value) => storage.set(key, value) } };
    const getCurrentCoordinates = () => {
        calls++;
        return new Promise((resolve, reject) => { locationResolve = resolve; locationReject = reject; });
    };
    const fetch = async (_, options) => {
        posts.push(JSON.parse(options.body));
        return { ok: true, json: async () => ({ available: true, answered }) };
    };
    runInNewContext(source, { document, navigator, window, getCurrentCoordinates, fetch, URL });
    listeners.DOMContentLoaded();
    return {
        dialog, posts, storage, get calls() { return calls; }, get option() { return option; },
        grant() { permission.state = 'granted'; change?.(); },
        locate() { locationResolve({ latitude: 55.7, longitude: 37.6 }); },
        reject() { locationReject(new Error('permission_denied')); },
    };
}

test('first catalogue visit requests location and shows offer only after successful coordinates', async () => {
    const h = harness(); await tick();
    assert.equal(h.calls, 1);
    assert.equal(h.dialog.open, false);
    assert.equal(h.option, null);
    h.grant(); await tick();
    assert.equal(h.calls, 1, 'permission change must not duplicate pending request');
    h.locate(); await tick();
    assert.equal(h.dialog.open, true);
    assert.equal(h.option.href, '/venues?sort=distance');
    assert.deepEqual(h.posts, [{ action: 'locate', latitude: 55.7, longitude: 37.6 }]);
});

test('dismissed prompt is not repeated in the same tab session; denial does not request at all', async () => {
    const h = harness(); await tick();
    h.reject(); await tick();
    assert.equal(h.dialog.open, false);
    assert.equal(h.option, null);
    const reload = harness({ storage: h.storage }); await tick();
    assert.equal(reload.calls, 0);
    const denied = harness({ state: 'denied' }); await tick();
    assert.equal(denied.calls, 0);
});

test('existing permission and browsers without Permissions API both request coordinates', async () => {
    const granted = harness({ state: 'granted', answered: true }); await tick();
    assert.equal(granted.calls, 1);
    granted.locate(); await tick();
    assert.equal(granted.dialog.open, false);
    assert.ok(granted.option);
    const fallback = harness({ permissions: false }); await tick();
    assert.equal(fallback.calls, 1);
    fallback.locate(); await tick();
    assert.ok(fallback.dialog.open);
});
