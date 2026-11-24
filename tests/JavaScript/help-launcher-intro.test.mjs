import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
    HELP_LAUNCHER_DELAY_MS,
    initHelpLauncherIntro,
} from '../../resources/themes/mskba_app/js/help-launcher-intro.js';

function fixture({ reducedMotion = false, readyState = 'loading', size = '50px', labelWidth = 80 } = {}) {
    const classes = new Set();
    const buttonListeners = {};
    const windowListeners = {};
    const timers = [];
    const properties = {};
    const label = { getBoundingClientRect: () => ({ width: labelWidth }) };
    const button = {
        classList: {
            add: (...names) => names.forEach((name) => classes.add(name)),
            remove: (...names) => names.forEach((name) => classes.delete(name)),
        },
        querySelector: (selector) => selector === '[data-help-intro-label]' ? label : null,
        addEventListener: (name, handler) => { buttonListeners[name] = handler; },
        style: { setProperty: (name, value) => { properties[name] = value; } },
    };
    const doc = {
        readyState,
        querySelector: (selector) => selector === '[data-help-launcher]' ? button : null,
    };
    const host = {
        matchMedia: () => ({ matches: reducedMotion }),
        getComputedStyle: () => ({ getPropertyValue: () => size }),
        addEventListener: (name, handler) => { windowListeners[name] = handler; },
        setTimeout: (callback, delay) => {
            const timer = { callback, delay, cancelled: false };
            timers.push(timer);
            return timer;
        },
        clearTimeout: (timer) => { timer.cancelled = true; },
    };
    initHelpLauncherIntro(doc, host);
    return { classes, windowListeners, buttonListeners, timers, properties };
}

test('waits for window load plus 2 seconds, then reveals measured Help pill and collapses once', () => {
    const f = fixture({ labelWidth: 78 });
    assert.equal(f.classes.has('is-pending-intro'), true);
    assert.equal(f.timers.length, 0);
    f.windowListeners.load();
    assert.equal(f.timers.length, 1);
    assert.equal(f.timers[0].delay, HELP_LAUNCHER_DELAY_MS);
    assert.equal(f.properties['--app-help-expanded-width'], '128px');

    f.timers[0].callback();
    assert.equal(f.classes.has('is-pending-intro'), false);
    assert.equal(f.classes.has('is-introducing'), true);

    f.buttonListeners.animationend({ animationName: 'app-help-intro' });
    assert.equal(f.classes.has('is-introducing'), false);
    assert.equal(f.classes.has('is-pending-intro'), false);
});

test('mobile button expands by exactly the rendered label width', () => {
    const f = fixture({ readyState: 'complete', size: '46px', labelWidth: 71.4 });
    assert.equal(f.properties['--app-help-expanded-width'], '118px');
    assert.equal(f.timers[0].delay, 2000);
});

test('reduced-motion mode shows the unchanged circular button immediately', () => {
    const f = fixture({ reducedMotion: true });
    assert.equal(f.classes.size, 0);
    assert.equal(f.timers.length, 0);
    assert.equal(f.windowListeners.load, undefined);
});

test('keyboard focus cancels a pending animation and keeps the button visible', () => {
    const f = fixture({ readyState: 'complete' });
    f.buttonListeners.focus();
    assert.equal(f.classes.has('is-pending-intro'), false);
    assert.equal(f.timers[0].cancelled, true);
    f.timers[0].callback();
    assert.equal(f.classes.has('is-introducing'), false);
});

test('launcher is a single accessible action, with CSS-only label clipping', () => {
    const blade = readFileSync(new URL('../../resources/themes/mskba_app/views/partials/help-launcher.blade.php', import.meta.url), 'utf8');
    const css = readFileSync(new URL('../../resources/themes/mskba_app/css/context-dialogs.css', import.meta.url), 'utf8');
    assert.match(blade, /data-help-intro-label aria-hidden="true">Помощь<\/span>/);
    assert.match(blade, /aria-label="Помощь по этой странице"/);
    assert.match(css, /justify-content: flex-end;/);
    assert.match(css, /overflow: hidden;/);
    assert.match(css, /44%, 64% \{ width: var\(--app-help-expanded-width\)/);
    assert.match(css, /animation: app-help-intro 2\.5s ease-in-out both;/);
    assert.match(css, /prefers-reduced-motion: reduce/);
});
