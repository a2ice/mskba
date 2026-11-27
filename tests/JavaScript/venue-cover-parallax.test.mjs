import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

// Import the real calculation without Vite's CSS loader; do not initialize a DOM.
const source = readFileSync(new URL('../../resources/js/components/venue-cover-parallax.js', import.meta.url), 'utf8');
const { venueCoverShift } = await import('data:text/javascript;base64,' + Buffer.from(source.replace(/^import .*;\n/m, '')).toString('base64'));

test('reversed direction: -10% at 85% and 0% at 15% of viewport', () => {
    assert.equal(venueCoverShift(1000, 1000), -10);
    assert.equal(venueCoverShift(850, 1000), -10);
    assert.equal(venueCoverShift(500, 1000), -5);
    assert.equal(Math.abs(venueCoverShift(150, 1000)), 0);
    assert.equal(Math.abs(venueCoverShift(-100, 1000)), 0);
});
test('position-based, bounded, monotonic and reversible across viewport sizes', () => {
    const tops = [900, 850, 750, 650, 500, 350, 250, 150, 0];
    const down = tops.map(top => venueCoverShift(top, 1000));
    const up = [...tops].reverse().map(top => venueCoverShift(top, 1000));
    assert.deepEqual(up, [...down].reverse());
    assert.ok(down.every((x, i) => x >= -10 && x <= 0 && (!i || x >= down[i - 1])));
    assert.equal(venueCoverShift(250, 500), -5);
    assert.equal(venueCoverShift(0, 0), 0);
    assert.equal(venueCoverShift(NaN, 500), 0);
});
test('scope excludes horizontal list; motion preference and observer guards retained', () => {
    assert.match(source, /not\(\.app-venues-catalog__items--list\)/);
    assert.match(source, /\.venues-catalog-results--cards/);
    assert.match(source, /prefers-reduced-motion: no-preference/);
    assert.match(source, /IntersectionObserver/);
    assert.match(source, /MutationObserver/);
    assert.match(source, /requestAnimationFrame/);
    const css = readFileSync(new URL('../../resources/css/venue-cover-parallax.css', import.meta.url), 'utf8');
    assert.match(css, /overflow: hidden/);
    assert.match(css, /scale\(1\.1\)/);
    assert.match(css, /prefers-reduced-motion: no-preference/);
});
