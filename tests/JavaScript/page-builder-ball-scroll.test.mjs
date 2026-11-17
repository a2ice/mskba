import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const script = readFileSync(new URL('../../docs/page-builder/filament-home/ball.js', import.meta.url), 'utf8');

function createHarness() {
    const listeners = {};
    const values = {};
    const frames = [];
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
        requestAnimationFrame: callback => {
            frames.push(callback);
            return frames.length;
        },
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
    const flush = () => { while (frames.length) frames.shift()(); };
    const scroll = y => { win.scrollY = y; listeners.scroll(); flush(); return sample(); };
    const swipeStep = y => { win.scrollY = y; listeners.scroll(); return frames.length; };
    const resize = (w, h) => {
        width = w;
        height = h;
        listeners.resize();
        flush();
        return sample();
    };
    const viewportHeight = next => {
        win.innerHeight = next;
        listeners.resize(); // Safari browser-bar collapse can fire resize.
        flush();
        return sample();
    };
    const setReducedMotion = on => { reducedMotion = on; listeners['motion:change'](); return sample(); };
    return { sample, scroll, swipeStep, flush, resize, viewportHeight, setReducedMotion, sprite };
}

test('native scroll coordinates and frame steps are deterministic, linear, and immediate', () => {
    const scene = createHarness();
    assert.deepEqual(scene.sample(), {
        left: 25.5, top: 44, scale: .22, frameX: '0%', frameY: '0%',
    });
    assert.equal(scene.sprite.src, 'https://dev.mskba.ru/images/ball-16.webp?v=photo-20261017-centered-v2');

    // The photographed sequence must reverse for a left-to-right roll.
    // The starting and landing sprite remain front-facing frame zero.
    const firstStep = scene.scroll(189 / 16);
    assert.equal(firstStep.frameX, '100%'); // frame 15
    assert.equal(firstStep.frameY, '100%'); // frame 15
    scene.scroll(0);

    const quarter = scene.scroll(47.25);
    assert.equal(quarter.left, 36.25);
    assert.equal(quarter.top, 58);
    assert.equal(quarter.scale, .415);
    assert.equal(quarter.frameY, '100%'); // reverse photo order: frame 12

    const half = scene.scroll(94.5);
    assert.equal(half.left, 47);
    assert.equal(half.top, 72);
    assert.equal(half.scale, .61);
    assert.equal(half.frameY, String(200 / 3) + '%'); // frame 8

    const landed = scene.scroll(189);
    assert.deepEqual(landed, {
        left: 68.5, top: 100, scale: 1, frameX: '0%', frameY: '0%',
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
        left: 15, top: 40.5, scale: .525, frameX: '0%', frameY: '0%',
    });
    const mobileMiddle = scene.scroll(94.5);
    assert.equal(mobileMiddle.scale, .7625, 'mobile ball scales linearly toward the unchanged landing size');
    const mobileLanded = scene.scroll(189);
    assert.deepEqual(mobileLanded, {
        left: 79.5, top: 100, scale: 1, frameX: '0%', frameY: '0%',
    }, 'final mobile size, position and frontal sprite frame must stay unchanged');
    scene.scroll(0);
    const tablet = scene.resize(768, 400);
    assert.deepEqual(tablet, {
        left: 21.5, top: 42.5, scale: .43, frameX: '0%', frameY: '0%',
    });
    assert.equal(scene.setReducedMotion(true).left, 71);
    assert.equal(scene.setReducedMotion(true).top, 100);
    assert.deepEqual(scene.setReducedMotion(false), tablet);
    const tabletLanded = scene.scroll(189);
    assert.equal(tabletLanded.left, 71);
    assert.equal(tabletLanded.top, 100);
    assert.equal(tabletLanded.scale, 1);
});

test('iPhone Safari toolbar resizing preserves scroll-to-ball mapping during touch flicks', () => {
    const scene = createHarness();
    scene.resize(375, 700);
    const mid = scene.scroll(105);
    assert.notEqual(mid.top, 100, 'midflight ball should not be in landed state');
    assert.deepEqual(scene.viewportHeight(610), mid, 'changing viewport height must not shift the ball');
    assert.deepEqual(scene.resize(375, 520), mid, 'height-only hero reflow must not change scroll progress');
    assert.deepEqual(scene.viewportHeight(800), mid);
    assert.deepEqual(scene.scroll(105), mid, 'same native scroll always gives same pose');
    assert.equal(scene.scroll(500).top, 100, 'landing remains exactly on bottom edge');
});

test('rapid scroll events coalesce into one frame using the latest native scroll position', () => {
    const scene = createHarness();
    assert.equal(scene.swipeStep(8), 1);
    assert.equal(scene.swipeStep(100), 1);
    assert.equal(scene.swipeStep(189), 1, 'no accumulation of pending frames');
    scene.flush();
    assert.deepEqual(scene.sample(), {
        left: 68.5, top: 100, scale: 1, frameX: '0%', frameY: '0%',
    });
    assert.equal(scene.swipeStep(250), 1);
    scene.flush();
    assert.equal(scene.sample().top, 100, 'scroll beyond landing does not change the ball');
});
