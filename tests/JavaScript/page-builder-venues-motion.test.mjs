import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/js/page-builder-venues-motion.js', import.meta.url), 'utf8');

function setup({ documentHeight = 1170, viewportHeight = 866, sectionTop = 520, sectionHeight = 650, reduced = false } = {}) {
    const vars = {};
    const events = {};
    const frames = [];
    const shifts = Array.from({ length: 4 }, () => ({}));
    const width = 1790;
    const nodes = shifts.map(variables => ({ style: {
        setProperty: (name, value) => { variables[name] = value; },
    } }));
    const ghost = {};
    const highlights = {};
    const venues = {
        querySelector: selector => selector.includes('ghost') ? ghost : highlights,
        getBoundingClientRect: () => ({
            top: sectionTop - win.scrollY,
            height: sectionHeight,
        }),
    };
    const story = {
        dataset: {},
        style: { setProperty: (name, value) => { vars[name] = value; } },
        querySelector: () => venues,
        querySelectorAll: () => nodes,
        getBoundingClientRect: () => ({ width }),
    };
    const media = { matches: reduced, addEventListener: () => {} };
    const win = {
        innerHeight: viewportHeight,
        scrollY: 0,
        matchMedia: () => media,
        addEventListener: (name, callback) => { events[name] = callback; },
        requestAnimationFrame: callback => { frames.push(callback); },
    };
    runInNewContext(source, {
        window: win,
        document: {
            querySelectorAll: () => [story],
            documentElement: { scrollHeight: documentHeight },
        },
    }, { filename: 'page-builder-venues-motion.js' });
    function scroll(to) {
        win.scrollY = to;
        events.scroll();
        while (frames.length) frames.shift()();
        return {
            progress: Number(story.dataset.venuesProgress),
            highlights: Number(vars['--venues-highlights-opacity']),
            ghost: Number(vars['--venues-ghost-shift'].replace('px', '')),
            player: Number(vars['--venues-player-shift'].replace('px', '')),
            layerShifts: shifts.map(x => Number(x['--venues-parallax-shift'].replace('px', ''))),
        };
    }
    return { scroll, vars, events };
}

test('short desktop page reaches headline landing and highlights by real scroll bottom', () => {
    const scene = setup();
    const top = scene.scroll(0);
    const mid = scene.scroll(150);
    const bottom = scene.scroll(1170 - 866);
    assert.equal(top.progress, 0);
    assert.equal(top.highlights, 0);
    assert.ok(top.ghost > 0);
    assert.ok(mid.progress > .4 && mid.progress < .6);
    assert.equal(bottom.progress, 1);
    assert.equal(bottom.ghost, 0, 'gray PLOSHCHADKI must finish at its authored x');
    assert.equal(bottom.highlights, 1, 'highlights must be visible before the page stops');
    assert.equal(bottom.layerShifts[0], 0, 'backdrop is stable');
    assert.equal(bottom.layerShifts[3], -142, 'the player rises more strongly than the hoop and fence');
    assert.equal(bottom.layerShifts[1], -44);
    assert.equal(bottom.layerShifts[2], -74);
    assert.equal(bottom.player, bottom.layerShifts[3], 'ball target and player share the same transform');
});

test('scrolling back resets appearance without timers or accumulated progress', () => {
    const scene = setup();
    const first = scene.scroll(70);
    scene.scroll(304);
    assert.deepEqual(scene.scroll(70), first);
});

test('reduced-motion makes highlights and heading visible while keeping layers still', () => {
    const scene = setup({ reduced: true });
    const top = scene.scroll(0);
    assert.equal(top.highlights, 1);
    assert.equal(top.ghost, 0);
    assert.deepEqual(top.layerShifts, [0, 0, 0, 0]);
});

test('a page too short to scroll leaves essential navigation fully visible', () => {
    const scene = setup({ documentHeight: 800, viewportHeight: 866 });
    assert.equal(scene.scroll(0).highlights, 1);
    assert.equal(scene.scroll(0).ghost, 0);
});
