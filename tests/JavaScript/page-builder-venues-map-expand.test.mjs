import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import test from 'node:test';

const source = readFileSync(
    new URL('../../resources/page-builder/venues-map.js', import.meta.url), 'utf8',
);

function scene({ mapReady = true } = {}) {
    const classes = new Set();
    const handlers = new Map();
    const makeElement = tagName => ({
        tagName, attributes: new Map(), children: [], listeners: new Map(),
        tabIndex: null, inert: false, focusCount: 0,
        setAttribute(name, value) { this.attributes.set(name, value); },
        getAttribute(name) { return this.attributes.get(name); },
        append(...items) { this.children.push(...items); },
        addEventListener(name, fn) { this.listeners.set(name, fn); },
        focus() { this.focusCount += 1; },
        click() {
            this.preventedNavigation = false;
            this.listeners.get('click')?.({ preventDefault: () => { this.preventedNavigation = true; } });
        },
    });
    const canvas = makeElement('div');
    canvas.id = 'mskba-venues-map-canvas';
    const filters = makeElement('div');
    const card = makeElement('div');
    const scrim = makeElement('div');
    const cta = makeElement('a');
    cta.href = '/venues'; // navigation still works if the JS bundle is absent
    const copy = makeElement('div');
    copy.querySelector = selector => selector === 'a.section-cta' ? cta : null;
    const root = {
        dataset: {},
        appended: [],
        classList: { toggle(key, enabled) {
            if (enabled) classes.add(key);
            else classes.delete(key);
        } },
        querySelector(selector) {
            return ({
                '#mskba-venues-map-canvas': canvas,
                '#mskba-venues-map-filters': filters,
                '#mskba-venues-map-card': card,
                '#mskba-venues-map-status': makeElement('p'),
                '.mskba-venues-map__copy': copy,
                '.mskba-venues-map__scrim': scrim,
            })[selector] || null;
        },
        querySelectorAll() { return []; },
        append(node) { this.appended.push(node); },
        addEventListener(name, cb) { handlers.set(name, cb); },
    };
    const window = {
        addEventListener(name, cb) { handlers.set(name, cb); },
        requestAnimationFrame(callback) { callback(); },
        IntersectionObserver: class { observe() {} disconnect() {} },
    };
    const context = {
        window, document: {
            querySelector: selector => selector === '.mskba-venues-map' ? root : null,
            createElement: makeElement,
        },
        console, URL, location: { origin: 'https://dev.mskba.ru' },
        IntersectionObserver: window.IntersectionObserver,
    };
    // Simulate the map-ready state without issuing live Yandex/API requests.
    // The unmodified source is used for failure/fallback tests.
    const code = mapReady
        ? source.replace('let mapReady = false;', 'let mapReady = true;')
        : source;
    runInNewContext(code, context);
    return {
        root, cta, copy, classes,
        button: root.appended[0],
        escape() { handlers.get('keydown')?.({ key: 'Escape' }); },
    };
}

test('CTA reveals full map, slides both overlays away and focuses return control', () => {
    const map = scene();
    assert.equal(map.root.dataset.venuesMapInitialized, '1');
    assert.equal(map.classes.has('is-map-expanded'), false);
    assert.equal(map.cta.href, '/venues');
    assert.equal(map.button.tabIndex, -1);

    map.cta.click();
    assert.equal(map.classes.has('is-map-expanded'), true);
    assert.equal(map.copy.inert, true, 'translated-out-of-view content is not keyboard reachable');
    assert.equal(map.cta.getAttribute('aria-expanded'), 'true');
    assert.equal(map.button.getAttribute('aria-expanded'), 'true');
    assert.equal(map.button.tabIndex, 0);
    assert.equal(map.button.focusCount, 1);
});

test('Space activates the CTA with keyboard button semantics', () => {
    const map = scene();
    let prevented = false;
    map.cta.listeners.get('keydown')?.({
        key: ' ',
        preventDefault() { prevented = true; },
    });
    assert.equal(prevented, true);
    assert.equal(map.classes.has('is-map-expanded'), true);
    map.button.click();
    assert.equal(map.classes.has('is-map-expanded'), false);
});

test('map errors or pending initialization preserve the real /venues navigation', () => {
    const map = scene({ mapReady: false });
    map.cta.click();
    assert.equal(map.cta.preventedNavigation, false);
    assert.equal(map.classes.has('is-map-expanded'), false);
    assert.equal(map.cta.href, '/venues');
    assert.equal(map.copy.inert, false);
    assert.equal(map.cta.getAttribute('role'), undefined);
});

test('compact arrow restores the overlay and keyboard Escape can restore it too', () => {
    const map = scene();
    map.cta.click();
    map.button.click();
    assert.equal(map.classes.has('is-map-expanded'), false);
    assert.equal(map.copy.inert, false);
    assert.equal(map.cta.getAttribute('aria-expanded'), 'false');
    assert.equal(map.button.tabIndex, -1);
    assert.equal(map.cta.focusCount, 1);

    map.cta.click();
    map.escape();
    assert.equal(map.classes.has('is-map-expanded'), false);
});
