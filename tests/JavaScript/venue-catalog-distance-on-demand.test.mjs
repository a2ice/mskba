import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const read = name => readFileSync(new URL('../../resources/themes/mskba_app/js/' + name, import.meta.url), 'utf8').replace(/^import .*;\n/gm, '');
const tick = () => new Promise(resolve => setImmediate(resolve));

function harness({ denied = false, failure = false } = {}) {
    const posts = [], navigations = [], handlers = [];
    let calls = 0, selection, click, reverseClick;
    const settings = { dataset: { autoPrompt: '0', locationUrl: '/venues/distance-location' } };
    const option = { href: '/venues?city_id=3&sort=distance', addEventListener(_, fn) { click = fn; }, setAttribute() {}, removeAttribute() {}, focus() {} };
    const reverse = {...option, href: '/venues?city_id=3&sort=distance_desc', addEventListener(_, fn) { reverseClick = fn; }};
    const picker = { dispatchEvent(event) { selection = event.detail; } };
    const status = { hidden: true };
    const document = {
        addEventListener(name, fn) { if (name === 'DOMContentLoaded') handlers.push(fn); },
        querySelectorAll(selector) { return selector === '[data-venue-distance-option]' ? [option, reverse] : []; },
        querySelector(selector) {
            return { '[data-venue-distance-prompt]': settings, '[data-venue-distance-option]': option,
                '[data-nearby-filter]': picker, '[data-venue-distance-error]': status,
                '#venues-catalog': { querySelector: () => ({}) } }[selector] ?? null;
        },
    };
    const window = { location: { assign(url) { navigations.push(url); } } };
    const getCurrentCoordinates = async () => { calls++; if (denied) throw Error('denied'); return { latitude: 55.7, longitude: 37.6 }; };
    const fetch = async (_, options) => { posts.push(JSON.parse(options.body)); return { ok: !failure, json: async () => ({ available: true }) }; };
    class CustomEvent { constructor(type, options) { this.type = type; this.detail = options.detail; } }
    const context = { document, window, navigator: {}, getCurrentCoordinates, fetch, CustomEvent, setTimeout, clearTimeout };
    runInNewContext(read('venue-catalog-distance.js'), context);
    runInNewContext(read('venue-catalog-distance-on-demand.js'), context);
    handlers.forEach(fn => fn());
    return { posts, navigations, status, get calls() { return calls; }, get selection() { return selection; },
        choose: () => click({ preventDefault() {} }), reverse: () => reverseClick({ preventDefault() {} }) };
}

test('no automatic geolocation or suggestion; explicit selection applies coordinates and preserves query', async () => {
    const h = harness(); await tick();
    assert.equal(h.calls, 0);
    await h.choose();
    assert.equal(h.calls, 1);
    assert.deepEqual(h.posts, [{ action: 'locate', latitude: 55.7, longitude: 37.6 }]);
    assert.deepEqual(h.navigations, ['/venues?city_id=3&sort=distance']);
});

test('denial opens shared manual picker; only confirmed point applies sorting', async () => {
    const h = harness({ denied: true });
    await h.choose();
    assert.ok(h.selection);
    assert.deepEqual(h.posts, []);
    assert.deepEqual(h.navigations, []);
    h.selection.select({ latitude: 56, longitude: 38 }); await tick();
    assert.deepEqual(h.posts, [{ action: 'locate', latitude: 56, longitude: 38 }]);
    assert.equal(h.navigations.length, 1);
});

test('cancel leaves previous sorting unchanged and permits retry', async () => {
    const h = harness({ denied: true });
    await h.choose(); h.selection.cancel();
    assert.equal(h.posts.length, 0);
    assert.equal(h.navigations.length, 0);
    await h.choose();
    assert.equal(h.calls, 2);
});

test('failed save never navigates or installs sorting', async () => {
    const h = harness({ failure: true }); await h.choose();
    assert.equal(h.navigations.length, 0);
    assert.equal(h.status.hidden, false);
});

test('shared picker routes external confirmation separately from radius filter', () => {
    const source = read('venue-catalog-nearby.js');
    assert.match(source, /if \(externalSelection\) \{[\s\S]*?selection\.select\([\s\S]*?return;[\s\S]*?\}\s*point = draft;/);
    assert.match(source, /selection\?\.cancel\?\.\(\)/);
});

test('reverse distance uses the exact selected link and manual selection', async () => {
    const h = harness({ denied: true });
    await h.reverse();
    h.selection.select({ latitude: 56, longitude: 38 }); await tick();
    assert.deepEqual(h.navigations, ['/venues?city_id=3&sort=distance_desc']);
});
