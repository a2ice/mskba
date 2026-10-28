import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import test from 'node:test';

const source = readFileSync(
    new URL('../../resources/themes/mskba_app/js/header.js', import.meta.url),
    'utf8',
).replace('if (import.meta.hot) import.meta.hot.dispose(() => controller.abort());', '');

function mountHeader(storage = new Map(), hasContextBar = true) {
    const callbacks = new Map();
    const headerCallbacks = new Map();
    const pinCallbacks = new Map();
    const pinAttributes = new Map();
    const pin = {
        title: '',
        setAttribute: (name, value) => pinAttributes.set(name, value),
        addEventListener: (name, cb) => {
            if (!pinCallbacks.has(name)) pinCallbacks.set(name, []);
            pinCallbacks.get(name).push(cb);
        },
    };
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
        addEventListener: (name, cb) => {
            if (!headerCallbacks.has(name)) headerCallbacks.set(name, []);
            headerCallbacks.get(name).push(cb);
        },
        getBoundingClientRect: () => ({ bottom: classes.has('is-scroll-revealed') ? 80 : -80 }),
    };
    const context = {
        offsetHeight: 44,
        querySelector: selector => selector === '[data-app-header-pin]' ? pin : null,
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
            '[data-app-context-bar]': hasContextBar ? context : null,
        })[selector] || null,
        addEventListener: listen,
        activeElement: null,
        documentElement: { style: { setProperty: (key, val) => cssVars.set(key, val) } },
    };
    const window = {
        scrollY: 0,
        sessionStorage: {
            getItem: key => storage.get(key) ?? null,
            setItem: (key, value) => storage.set(key, value),
        },
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
        enterHeader(pointerType = 'mouse') {
            for (const cb of headerCallbacks.get('pointerenter') || []) cb({ pointerType });
            while (frames.length) frames.shift()();
        },
        clickPin() {
            for (const cb of pinCallbacks.get('click') || []) cb({});
            while (frames.length) frames.shift()();
        },
        get pressed() { return pinAttributes.get('aria-pressed'); },
        get pinLabel() { return pinAttributes.get('aria-label'); },
        get savedPin() { return storage.get('mskba:header-pinned'); },
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

test('hovering the upper viewport or header never reveals the navigation', () => {
    const page = mountHeader();
    page.scrollTo(500);
    page.moveMouse(0);
    page.enterHeader();
    assert.equal(page.classes.has('is-scroll-revealed'), false);
    assert.equal(page.cssVars.get('--app-sticky-offset'), '44px');
});

test('pin button reveals the header, survives downward scrolling and rotates its chevron', () => {
    const page = mountHeader();
    page.scrollTo(500);
    assert.equal(page.pressed, 'false');

    page.clickPin();
    assert.equal(page.classes.has('is-scroll-revealed'), true);
    assert.equal(page.cssVars.get('--app-sticky-offset'), '124px');
    assert.equal(page.pressed, 'true');
    assert.match(page.pinLabel, /Скрыть шапку/);

    page.scrollTo(800);
    page.scrollTo(1200);
    assert.equal(page.classes.has('is-scroll-revealed'), true);

    page.clickPin();
    assert.equal(page.pressed, 'false');
    assert.equal(page.classes.has('is-scroll-revealed'), false);
    assert.equal(page.cssVars.get('--app-sticky-offset'), '44px');
    assert.match(page.pinLabel, /закрепить шапку/);

    // The legacy upward-scroll reveal is restored immediately after unpin.
    page.scrollTo(1100);
    assert.equal(page.classes.has('is-scroll-revealed'), true);
    page.scrollTo(1200);
    assert.equal(page.classes.has('is-scroll-revealed'), false);
});

test('unpin hides a previously upward-revealed header immediately', () => {
    const page = mountHeader();
    page.scrollTo(500);
    page.scrollTo(400);
    assert.equal(page.classes.has('is-scroll-revealed'), true);
    page.clickPin();
    assert.equal(page.pressed, 'true');
    page.clickPin();
    assert.equal(page.classes.has('is-scroll-revealed'), false);
});

test('pin preference survives navigation within the same tab', () => {
    const storage = new Map();
    const first = mountHeader(storage);
    first.clickPin();
    assert.equal(first.savedPin, '1');

    const nextPage = mountHeader(storage);
    assert.equal(nextPage.pressed, 'true');
    nextPage.scrollTo(600);
    assert.equal(nextPage.classes.has('is-scroll-revealed'), true);

    nextPage.clickPin();
    assert.equal(nextPage.savedPin, '0');
    assert.equal(mountHeader(storage).pressed, 'false');
});

test('the contextual toggle is a real, keyboard-accessible button with a rotated active icon', () => {
    const fs = readFileSync;
    const markup = fs(new URL('../../resources/themes/mskba_app/views/partials/context-bar.blade.php', import.meta.url), 'utf8');
    const css = fs(new URL('../../resources/themes/mskba_app/css/context-bar.css', import.meta.url), 'utf8');
    const javascript = source;
    assert.match(markup, /<button[^>]+data-app-header-pin[^>]+aria-pressed="false"/s);
    assert.match(markup, /m6 9 6 6 6-6/);
    assert.match(css, /\.app-context-header-pin\[aria-pressed="true"\]\s*>\s*svg\s*\{\s*transform:\s*rotate\(180deg\)/);
    assert.match(javascript, /sessionStorage\?\.setItem/);
    assert.doesNotMatch(javascript, /addEventListener\('pointermove'/);
});

test('a home page without contextual controls never traps a stored pin', () => {
    const storage = new Map([['mskba:header-pinned', '1']]);
    const page = mountHeader(storage, false);
    assert.equal(page.pressed, undefined);
    assert.equal(page.classes.has('is-scroll-revealed'), false);
    page.scrollTo(500);
    assert.equal(page.classes.has('is-scroll-revealed'), false);
    page.scrollTo(300);
    assert.equal(page.classes.has('is-scroll-revealed'), true);
});
