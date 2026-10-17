import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const script = readFileSync(new URL('../../resources/js/page-builder-rolling-ball.js', import.meta.url), 'utf8');

function createHarness() {
    const listeners = {};
    const values = {};
    const rootStyle = {
        left: null,
        top: null,
        setProperty: (key, val) => { values[key] = val; },
    };
    let width = 1280;
    let height = 450;
    let reducedMotion = false;
    const media = {
        get matches() { return reducedMotion; },
        addEventListener: (name, callback) => { listeners['motion:' + name] = callback; },
    };
    const sprite = {
        complete: true,
        naturalWidth: 1024,
        naturalHeight: 1024,
        src: 'https://dev.mskba.ru/images/ball-16.webp',
        currentSrc: 'https://dev.mskba.ru/images/ball-16.webp',
        getAttribute: () => '/images/ball-16.webp',
    };
    const slider = {
        append: () => {},
        getBoundingClientRect: () => ({
            width,
            height,
            top: 120 - win.scrollY,
        }),
    };
    const scene = { querySelector: () => slider };
    const root = {
        dataset: {},
        style: rootStyle,
        closest: () => scene,
        querySelector: () => sprite,
    };
    const win = {
        scrollY: 0,
        innerHeight: 800,
        matchMedia: () => media,
        addEventListener: (name, fn) => { listeners[name] = fn; },
        requestAnimationFrame: () => { throw new Error('Time-based motion must not be used'); },
    };
    const context = {
        window: win,
        document: { querySelectorAll: () => [root] },
        location: { href: 'https://dev.mskba.ru/' },
        URL,
    };
    runInNewContext(script, context, { filename: 'page-builder-rolling-ball.js' });
    const sample = () => ({
        left: Number.parseFloat(root.style.left),
        top: Number.parseFloat(root.style.top),
        scale: Number.parseFloat(values['--ball-scale']),
        frameX: values['--ball-frame-x'],
        frameY: values['--ball-frame-y'],
    });
    const scroll = y => { win.scrollY = y; listeners.scroll(); return sample(); };
    const resize = (w, h) => {
        width = w;
        height = h;
        listeners.resize();
        return sample();
    };
    const setReducedMotion = on => { reducedMotion = on; listeners['motion:change'](); return sample(); };
    return { sample, scroll, resize, setReducedMotion, sprite };
}

test('native scroll coordinates and frame steps are deterministic, linear, and immediate', () => {
    const scene = createHarness();
    assert.deepEqual(scene.sample(), {
        left: 74.5, top: 44, scale: .22, frameX: '0%', frameY: '0%',
    });
    assert.equal(scene.sprite.src, 'https://dev.mskba.ru/images/ball-16.webp?v=photo-20261017-centered-v2');

    const quarter = scene.scroll(47.25);
    assert.equal(quarter.left, 63.75);
    assert.equal(quarter.top, 58);
    assert.equal(quarter.scale, .415);
    assert.equal(quarter.frameY, String(100 / 3) + '%'); // frame 4

    const half = scene.scroll(94.5);
    assert.equal(half.left, 53);
    assert.equal(half.top, 72);
    assert.equal(half.scale, .61);
    assert.equal(half.frameY, String(200 / 3) + '%'); // frame 8

    const landed = scene.scroll(189);
    assert.deepEqual(landed, {
        left: 31.5, top: 100, scale: 1, frameX: '0%', frameY: '0%',
    });
    assert.deepEqual(scene.scroll(220), landed, 'the ball must not drop again');
    assert.deepEqual(scene.scroll(500), landed, 'the ball stays in local Hero coordinates');
    assert.deepEqual(scene.scroll(94.5), half, 'reverse scroll reconstructs the exact frame and coordinates');
    assert.deepEqual(scene.scroll(189), landed);
});

test('responsive breakpoints and reduced motion retain their landing coordinates', () => {
    const scene = createHarness();
    const mobile = scene.resize(375, 320);
    assert.deepEqual(mobile, {
        left: 85, top: 40.5, scale: .75, frameX: '0%', frameY: '0%',
    });
    const tablet = scene.resize(768, 400);
    assert.deepEqual(tablet, {
        left: 78.5, top: 42.5, scale: .43, frameX: '0%', frameY: '0%',
    });
    assert.equal(scene.setReducedMotion(true).left, 29);
    assert.equal(scene.setReducedMotion(true).top, 100);
    assert.deepEqual(scene.setReducedMotion(false), tablet);
});
