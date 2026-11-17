import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const root = new URL('../../resources/themes/mskba_app/', import.meta.url);

test('mobile menu becomes compact at the context bar and returns to normal above it', () => {
    const callbacks = {};
    const frames = [];
    const classes = new Set();
    const media = { matches: true, addEventListener(event, handler) { callbacks['media-' + event] = handler; } };
    let top = 160;
    let bottom = 900;

    const aside = {
        classList: {
            add(name) { classes.add(name); },
            remove(name) { classes.delete(name); },
            toggle(name, value) { if (value) classes.add(name); else classes.delete(name); },
        },
    };
    const layout = {
        querySelector() { return aside; },
        getBoundingClientRect() { return { top, bottom }; },
    };

    runInNewContext(readFileSync(new URL('js/account-mobile-sticky.js', root), 'utf8'), {
        document: {
            querySelector(selector) { return selector === '.app-account-layout' ? layout : null; },
            documentElement: {},
            addEventListener(event, handler) { callbacks['document-' + event] = handler; },
        },
        window: {
            matchMedia() { return media; },
            addEventListener(event, handler) { callbacks[event] = handler; },
        },
        requestAnimationFrame(callback) { frames.push(callback); return 1; },
        getComputedStyle() {
            return { getPropertyValue(name) { return name === '--header-height' ? '64px' : '44px'; } };
        },
    });
    const flush = () => { while (frames.length) frames.shift()(); };

    flush();
    assert.equal(classes.has('is-stuck'), false, 'menu stays normal above context bar');

    top = 108; // header 64 + breadcrumbs 44
    callbacks.scroll();
    flush();
    assert.equal(classes.has('is-stuck'), true, 'menu compacts exactly at sticky boundary');

    top = -450;
    bottom = 700;
    callbacks.scroll();
    flush();
    assert.equal(classes.has('is-stuck'), true, 'menu remains sticky during long scroll');

    media.matches = false;
    callbacks['media-change']();
    flush();
    assert.equal(classes.has('is-stuck'), false, 'desktop sidebar does not compact');

    media.matches = true;
    top = 160;
    callbacks.resize();
    flush();
    assert.equal(classes.has('is-stuck'), false, 'returns to normal when scrolled back up');

    top = -100;
    bottom = 130;
    callbacks.scroll();
    flush();
    assert.equal(classes.has('is-stuck'), false, 'releases after account layout ends');
});

test('mobile sticky bar has full width and does not disturb desktop sidebar style', () => {
    const css = readFileSync(new URL('css/account-layout.css', root), 'utf8');
    assert.match(css, /@media\(max-width:900px\)/);
    assert.match(css, /\.app-account-layout__aside\s*\{[^}]*position:sticky;/);
    assert.match(css, /\.app-account-layout__aside\.is-stuck\s*\{[^}]*width:calc\(100% \+ 48px\);/);
    assert.match(css, /width:calc\(100% \+ 32px\);/);
    assert.match(css, /min-height:35px;/);
    assert.match(css, /\.app-account-nav--mobile\[open\] \.app-account-nav__links\s*\{[^}]*position:absolute;/);
});
