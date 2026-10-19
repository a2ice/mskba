import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/js/page-builder-rolling-ball.js', import.meta.url), 'utf8');

function makeScene({ documentHeight } = {}) {
    const frames = [];
    const events = {};
    const props = {};
    let parent = null;
    const root = {
        dataset: {},
        style: {
            left: '',
            top: '',
            setProperty: (name, value) => { props[name] = value; },
        },
        closest: () => scene,
        querySelector: () => sprite,
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
        append: el => { assert.equal(el, root); parent = 'slider'; },
        getBoundingClientRect: () => ({
            left: 100, top: 120 - win.scrollY, width: 1000, height: 500,
        }),
    };
    const marker = {
        getBoundingClientRect: () => ({ left: 500, top: 700 - win.scrollY }),
    };
    const visual = {
        append: el => { assert.equal(el, root); parent = 'visual'; },
        getBoundingClientRect: () => ({
            left: 100, top: 560 - win.scrollY, width: 600, height: 620,
        }),
    };
    const venues = {
        querySelector: selector => selector === '[data-mskba-ball-target]' ? marker
            : selector === '[data-mskba-venues-visual]' ? visual : null,
        getBoundingClientRect: () => ({
            left: 0, top: 620 - win.scrollY, width: 1280, height: 600,
        }),
    };
    const story = {
        querySelector: selector => selector === '[data-mskba-venues-scene]' ? venues : null,
        getBoundingClientRect: () => ({
            left: 0, top: -win.scrollY, width: 1280, height: 1220,
        }),
    };
    const scene = {
        closest: selector => selector === '[data-mskba-home-story]' ? story : null,
        querySelector: selector => selector === '[data-mskba-hero-slider]' ? slider : null,
    };
    const media = { matches: false, addEventListener: () => {} };
    const win = {
        innerHeight: 800,
        scrollY: 0,
        matchMedia: () => media,
        addEventListener: (name, callback) => { events[name] = callback; },
        requestAnimationFrame: callback => { frames.push(callback); },
    };
    runInNewContext(source, {
        window: win,
        document: {
            querySelectorAll: () => [root],
            documentElement: documentHeight ? { scrollHeight: documentHeight } : undefined,
        },
        location: { href: 'https://dev.mskba.ru/' },
        URL,
    }, { filename: 'page-builder-rolling-ball.js' });

    function scroll(y) {
        win.scrollY = y;
        events.scroll();
        while (frames.length) frames.shift()();
        return {
            parent,
            x: parseFloat(root.style.left),
            y: parseFloat(root.style.top),
            cssX: root.style.left,
            scale: parseFloat(props['--ball-scale']),
            frameX: props['--ball-frame-x'],
            frameY: props['--ball-frame-y'],
            tileSize: props['--ball-tile-size'],
        };
    }
    return { scroll };
}

test('the single ball transfers from Hero to Venues without a boundary jump and returns', () => {
    const scene = makeScene();
    const lastHero = scene.scroll(189);
    assert.equal(lastHero.parent, 'slider');
    assert.equal(lastHero.cssX, '68.500%');
    assert.equal(lastHero.scale, 1);
    assert.equal(lastHero.tileSize, '195px');

    const handoff = scene.scroll(190);
    assert.equal(handoff.parent, 'visual', 'the ball must remain within the boxed player column');
    assert.ok(Math.abs(handoff.x - 685) < 2, 'first handoff x must match Hero landing in visual coordinates');
    assert.ok(Math.abs(handoff.y - 60) < 2, 'first handoff y must match Hero landing in visual coordinates');
    assert.ok(handoff.scale < 1 && handoff.scale > .99);

    const landed = scene.scroll(1000);
    assert.equal(landed.parent, 'visual');
    assert.equal(landed.x, 400);
    assert.equal(landed.scale, .48);
    assert.equal(landed.frameX, '0%');
    assert.equal(landed.frameY, '0%');
    assert.equal(scene.scroll(1300).scale, .48, 'landed size remains unchanged further down');

    const reverse = scene.scroll(189);
    assert.equal(reverse.parent, 'slider');
    assert.equal(reverse.cssX, lastHero.cssX);
    assert.equal(reverse.scale, lastHero.scale);
    assert.equal(scene.scroll(0).parent, 'slider');
});

test('Venues handoff scroll remains deterministic for the same absolute page position', () => {
    const scene = makeScene();
    const first = scene.scroll(320);
    scene.scroll(850);
    const reverse = scene.scroll(320);
    assert.deepEqual(reverse, first, 'no accumulated swipe progress or time-based follower');
});

test('short end-of-page scroll range still lands the ball at the actual player marker', () => {
    // Viewport is 800px high, page height 1104px -> maxScroll=304.
    // Hero uses its existing 189px. Only 115px remain for the second route.
    const scene = makeScene({ documentHeight: 1104 });
    assert.equal(scene.scroll(189).parent, 'slider');
    const landed = scene.scroll(304);
    assert.equal(landed.parent, 'visual');
    assert.equal(landed.x, 400);
    assert.equal(landed.scale, .48);
    assert.equal(landed.frameX, '0%');
    assert.equal(landed.frameY, '0%');
    assert.equal(scene.scroll(189).parent, 'slider',
        'reverse native scroll restores the unchanged first-scene endpoint');
});
