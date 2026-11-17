import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import test from 'node:test';

const source = readFileSync(
    new URL('../../resources/themes/mskba_app/js/features/home-venues-map-expand.js', import.meta.url),
    'utf8',
);

function scene({ loaded = false } = {}) {
    const classes = new Set();
    const documentListeners = new Map();
    const elements = {};
    let changedToolbar = () => {};
    let observerDisconnected = false;

    function makeElement(tagName) {
        return {
            tagName,
            attributes: new Map(),
            listeners: new Map(),
            children: [],
            focusCount: 0,
            inert: false,
            tabIndex: null,
            setAttribute(key, value) { this.attributes.set(key, value); },
            getAttribute(key) { return this.attributes.get(key); },
            append(node) { this.children.push(node); },
            addEventListener(key, callback) { this.listeners.set(key, callback); },
            focus() { this.focusCount += 1; },
            click() {
                this.preventedNavigation = false;
                this.listeners.get('click')?.({ preventDefault: () => { this.preventedNavigation = true; } });
            },
        };
    }

    const canvas = makeElement('div');
    canvas.id = 'mskba-venues-map-canvas';
    canvas.firstElementChild = loaded ? makeElement('div') : null;
    const toolbar = makeElement('div');
    toolbar.ready = loaded;
    toolbar.querySelector = query => query === 'select' && toolbar.ready ? makeElement('select') : null;
    const copy = makeElement('div');
    const scrim = makeElement('div');
    const link = makeElement('a');
    link.href = '/venues';
    copy.querySelector = query => query === 'a.section-cta[href]' ? link : null;

    const plate = {
        dataset: {},
        appended: [],
        classList: { toggle(key, next) { if (next) classes.add(key); else classes.delete(key); } },
        querySelector(query) {
            return ({
                '#mskba-venues-map-canvas': canvas,
                '#mskba-venues-map-filters': toolbar,
                '.mskba-venues-map__scrim': scrim,
                '.mskba-venues-map__copy': copy,
            })[query] || null;
        },
        append(node) { this.appended.push(node); },
    };
    const document = {
        querySelector: query => query === '.mskba-venues-map' ? plate : null,
        createElement: makeElement,
        addEventListener(key, callback) { documentListeners.set(key, callback); },
    };
    const window = { addEventListener() {} };
    class MutationObserverMock {
        constructor(callback) { changedToolbar = callback; }
        observe() {}
        disconnect() { observerDisconnected = true; }
    }
    runInNewContext(source, { document, window, MutationObserver: MutationObserverMock });
    return {
        classes, plate, copy, link,
        button: plate.appended[0],
        setLoaded() {
            canvas.firstElementChild = makeElement('div');
            toolbar.ready = true;
            changedToolbar();
        },
        observerDisconnected: () => observerDisconnected,
        escape() { documentListeners.get('keydown')?.({ key: 'Escape' }); },
    };
}

test('pending or failed maps retain their /venues fallback without exposing an empty map', () => {
    const map = scene();
    assert.equal(map.plate.dataset.expandEnhancement, '1');
    assert.equal(map.button.tabIndex, -1);
    assert.equal(map.link.href, '/venues');
    map.link.click();
    assert.equal(map.link.preventedNavigation, false);
    assert.equal(map.classes.has('is-map-expanded'), false);
    assert.equal(map.copy.inert, false);
    assert.equal(map.link.getAttribute('role'), undefined);
});

test('map readiness triggers the accessible toggle without modifying Filament markup', () => {
    const map = scene();
    map.setLoaded();
    assert.equal(map.observerDisconnected(), true);
    assert.equal(map.link.getAttribute('role'), 'button');
    map.link.click();
    assert.equal(map.link.preventedNavigation, true);
    assert.equal(map.classes.has('is-map-expanded'), true);
    assert.equal(map.copy.inert, true);
    assert.equal(map.button.focusCount, 1);
    assert.equal(map.button.getAttribute('aria-expanded'), 'true');
    assert.equal(map.button.tabIndex, 0);
});

test('return arrow and Escape reveal copy again, while Space opens the map', () => {
    const map = scene({ loaded: true });
    const event = { key: ' ', prevented: false, preventDefault() { this.prevented = true; } };
    map.link.listeners.get('keydown')(event);
    assert.equal(event.prevented, true);
    assert.equal(map.classes.has('is-map-expanded'), true);
    map.button.click();
    assert.equal(map.classes.has('is-map-expanded'), false);
    assert.equal(map.link.focusCount, 1);
    assert.equal(map.copy.inert, false);
    map.link.click();
    map.escape();
    assert.equal(map.classes.has('is-map-expanded'), false);
    assert.equal(map.button.tabIndex, -1);
});
