import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import test from 'node:test';

const source = readFileSync(
    new URL('../../resources/themes/mskba_app/js/header.js', import.meta.url),
    'utf8',
).replace('if (import.meta.hot) import.meta.hot.dispose(() => controller.abort());', '');

function mountHeader() {
    const callbacks = new Map();
    const frames = [];
    const cssVars = new Map();
    const classes = new Set();
    const classList = {
        toggle: (name, enabled) => enabled ? classes.add(name) : classes.delete(name),
        contains: name => classes.has(name),
    };
    const header = {
        offsetHeight: 80,
        classList,
        querySelector: () => null,
        contains: () => false,
        addEventListener: () => {},
        getBoundingClientRect: () => ({ bottom: classes.has('is-scroll-revealed') ? 80 : -80 }),
    };
    const context = {
        offsetHeight: 44,
        getBoundingClientRect: () => ({ bottom: (Number.parseFloat(cssVars.get('--app-visible-header-height')) || 0) + 44 }),
    };
    const listen = (name, cb) => {
        if (!callbacks.has(name)) callbacks.set(name, []);
        callbacks.get(name).push(cb);
    };
    const document = {
        querySelectorAll: () => [],
        querySelector: selector => ({
            '[data-app-header]': header,
            '[data-app-context-bar]': context,
        })[selector] || null,
        addEventListener: listen,
        activeElement: null,
        documentElement: { style: { setProperty: (key, val) => cssVars.set(key, val) } },
    };
    const window = {
        scrollY: 0,
        addEventListener: listen,
        dispatchEvent: () => {},
        requestAnimationFrame: callback => { frames.push(callback); return frames.length; },
    };

    runInNewContext(source, {
        document, window, AbortController, CustomEvent: class {
            constructor(type, options) { this.type = type; this.detail = options.detail; }
        },
    });

    const emit = (eventName, event = {}) => {
        for (const cb of callbacks.get(eventName) || []) cb(event);
        while (frames.length) frames.shift()();
    };

    return {
        classes,
        cssVars,
        scrollTo(y) {
            window.scrollY = y;
            emit('scroll');
        },
        moveMouse(y) { emit('pointermove', { pointerType: 'mouse', clientY: y }); },
    };
}

test('upward reveal remains sticky across the natural top-of-page boundary', () => {
    const page = mountHeader();
    page.scrollTo(300);
    assert.equal(page.classes.has('is-scroll-revealed'), false);

    page.scrollTo(200);
    assert.equal(page.classes.has('is-scroll-revealed'), true);

    // Old behavior revoked the class at <= headerHeight (80px),
    // causing the visually observed jump during an upward scroll.
    page.scrollTo(80);
    page.scrollTo(40);
    page.scrollTo(1);
    page.scrollTo(0);
    assert.equal(page.classes.has('is-scroll-revealed'), true);
    assert.equal(page.cssVars.get('--app-sticky-offset'), '124px');

    // The first intentional down-scroll hides it again.
    page.scrollTo(20);
    assert.equal(page.classes.has('is-scroll-revealed'), false);
    assert.equal(page.cssVars.get('--app-sticky-offset'), '44px');
});

test('top 18px mouse reveal works after scrolling down', () => {
    const page = mountHeader();
    page.scrollTo(500);
    page.moveMouse(10);
    assert.equal(page.classes.has('is-scroll-revealed'), true);
    page.moveMouse(170);
    assert.equal(page.classes.has('is-scroll-revealed'), false);
});
