import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import test from 'node:test';

const script = readFileSync(new URL(
    '../../resources/themes/mskba_app/js/features/page-builder-native-lqip.js', import.meta.url,
), 'utf8');
const uuid = '60d4d65b-308c-40aa-8466-158466632107';
const full = '/media/site/' + uuid;
const mini = full + '/lqip';

function scene({ known = true, player = true, css = false, dynamic = false, picture = false } = {}) {
    const downloads = [];
    let notify = null;
    const attrs = new Map([
        ['src', known ? mini : full],
        ...(known ? [['data-mskba-lqip-full-src', full]] : []),
        ...(player ? [['data-mskba-lqip-venue-player', '1']] : []),
    ]);
    const sourceAttrs = new Map([
        ['data-mskba-lqip-full-srcset', full],
    ]);
    const source = {
        media: '(max-width: 640px)',
        getAttribute: name => sourceAttrs.get(name) || null,
        set srcset(value) { this.restored = value; },
    };
    const wrapper = {
        querySelectorAll: selector => selector.startsWith('source') ? [source] : [],
    };
    const img = {
        tagName: 'IMG', nodeType: 1, loading: 'eager', isConnected: true,
        complete: false, naturalWidth: 0,
        getAttribute: name => attrs.get(name) || null,
        setAttribute(name, value) { attrs.set(name, value); },
        hasAttribute: name => attrs.has(name),
        closest: selector => selector === 'picture' && picture ? wrapper : null,
        set src(value) { attrs.set('src', value); },
        get src() { return attrs.get('src'); },
    };
    const bg = {
        nodeType: 1, tagName: 'DIV', id: 'tournaments-background',
        isConnected: true, style: { backgroundImage: dynamic ? 'url("' + full + '")' : '' },
        closest: () => null, querySelectorAll: () => [],
    };
    const canvas = {
        nodeType: 1, tagName: 'DIV', id: '', style: {},
        closest: () => null,
        querySelectorAll(selector) {
            if (selector.startsWith('img')) return [img];
            if (selector.startsWith('[style')) return dynamic ? [bg] : [];
            return [];
        },
    };
    const style = { textContent: css ? '.court{background-image:url("' + mini + '");background-size:cover}' : '', isConnected: true };
    const manifest = { textContent: JSON.stringify(known ? { [uuid]: mini } : {}) };
    const document = {
        readyState: 'complete',
        querySelector(selector) {
            if (selector === '.mskba-page-builder') return canvas;
            if (selector === '[data-page-builder-lqip-manifest]') return manifest;
            if (selector === 'style[data-mskba-lqip-css]') return css ? style : null;
            return null;
        },
    };
    class ImageMock {
        naturalWidth = 0;
        complete = false;
        set src(value) { this.url = value; downloads.push(this); }
        get src() { return this.url; }
        decode() { return Promise.resolve(); }
        succeed() { this.naturalWidth = 320; this.onload?.(); }
        fail() { this.onerror?.(); }
    }
    class MutationObserverMock {
        constructor(callback) { notify = callback; }
        observe() {}
        disconnect() {}
    }
    const window = {
        location: { href: 'https://dev.mskba.ru/#venues', origin: 'https://dev.mskba.ru' },
        matchMedia: () => ({ matches: true }),
        addEventListener() {},
    };
    runInNewContext(script, { document, window, URL, Image: ImageMock,
        MutationObserver: MutationObserverMock, console });

    return {
        img, bg, style, source, downloads,
        setLateBackground(value) {
            bg.style.backgroundImage = value;
            notify([{ type: 'attributes', target: bg }]);
        },
    };
}

test('player starts with a real LQIP src in CSS position, then swaps the SAME image', async () => {
    const s = scene();
    assert.equal(s.img.src, mini);
    assert.equal(s.downloads.length, 1);
    assert.equal(s.downloads[0].src, full);
    s.downloads[0].succeed();
    await Promise.resolve();
    await Promise.resolve();
    assert.equal(s.img.src, full);
    assert.equal(s.img.getAttribute('data-mskba-lqip-ready'), '1');
});

test('full image failure keeps valid miniature visible without DOM cloning', async () => {
    const s = scene();
    s.downloads[0].fail();
    await Promise.resolve();
    assert.equal(s.img.src, mini);
});

test('missing LQIP does not add placeholders or image requests', () => {
    const s = scene({ known: false });
    assert.equal(s.downloads.length, 0);
    assert.equal(s.img.src, full);
});

test('CSS-authored background starts with lightweight URL, full replaces it after decode', async () => {
    const s = scene({ css: true });
    assert.match(s.style.textContent, /\/lqip/);
    assert.equal(s.downloads.length, 2); // player and stylesheet original
    const background = s.downloads.find(x => x.src === full);
    background.succeed();
    await Promise.resolve();
    await Promise.resolve();
    assert.doesNotMatch(s.style.textContent, /\/lqip/);
    assert.match(s.style.textContent, /background-size:cover/);
});

test('late API-provided tournament background uses same CSS box, no overlay element', async () => {
    const s = scene({ dynamic: false });
    s.setLateBackground('linear-gradient(#000,#fff), url("' + full + '")');
    assert.match(s.bg.style.backgroundImage, /\/lqip/);
    assert.equal(s.downloads.length, 2);
    s.downloads[1].succeed();
    await Promise.resolve();
    await Promise.resolve();
    assert.doesNotMatch(s.bg.style.backgroundImage, /\/lqip/);
    assert.match(s.bg.style.backgroundImage, /linear-gradient/);
});

test('picture source switches mobile srcset with the same image without coordinates changes', async () => {
    const s = scene({ picture: true });
    assert.equal(s.img.src, mini);
    s.downloads[0].succeed();
    await Promise.resolve();
    await Promise.resolve();
    assert.equal(s.source.restored, full);
    assert.equal(s.img.src, full);
});
