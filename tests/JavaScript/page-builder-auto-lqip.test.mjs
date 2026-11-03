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

function scene({ known = true, cached = false, dynamic = false, picture = false, legacySource = false } = {}) {
    const downloads = [];
    let notify = null;
    const attrs = new Map([
        ['src', full],
        ...(known ? [['data-mskba-lqip-full-src', full], ['data-mskba-lqip-preview-src', mini],
            ['data-mskba-lqip-added-background', '1']] : []),
    ]);
    let onLoad = null;
    const style = {
        backgroundImage: known ? 'url(' + mini + ')' : '',
        backgroundSize: known ? 'contain' : '',
        backgroundPosition: known ? 'center' : '',
        backgroundRepeat: known ? 'no-repeat' : '',
        removeProperty(name) {
            const key = name.replace(/-([a-z])/g, (_, c) => c.toUpperCase());
            this[key] = '';
        },
    };
    const img = {
        tagName: 'IMG', nodeType: 1, loading: 'eager', isConnected: true,
        complete: cached, naturalWidth: cached ? 320 : 0, style,
        getAttribute: name => attrs.get(name) || null,
        setAttribute(name, value) { attrs.set(name, value); },
        removeAttribute(name) { attrs.delete(name); },
        hasAttribute: name => attrs.has(name),
        closest: selector => selector === 'picture' && picture ? wrapper : null,
        set src(value) { attrs.set('src', value); },
        get src() { return attrs.get('src'); },
        decode: () => Promise.resolve(),
        addEventListener(type, cb) { if (type === 'load') onLoad = cb; },
        loaded() {
            this.complete = true;
            this.naturalWidth = 320;
            onLoad?.();
        },
    };
    const sourceAttrs = new Map(legacySource ? [['data-mskba-lqip-full-srcset', full]] : []);
    const source = {
        tagName: 'SOURCE', nodeType: 1, isConnected: true,
        media: '(max-width: 640px)', srcset: legacySource ? mini : full,
        hasAttribute: name => sourceAttrs.has(name),
        getAttribute: name => sourceAttrs.get(name) || null,
        closest: () => null,
    };
    const wrapper = {
        querySelectorAll: selector => selector.startsWith('source') ? [source] : [],
        querySelector: selector => selector.startsWith('img') ? img : null,
    };
    const bg = {
        nodeType: 1, tagName: 'DIV', id: 'tournaments-background',
        isConnected: true,
        style: { backgroundImage: dynamic ? 'linear-gradient(#000,#fff), url("' + full + '")' : '' },
        closest: () => null, querySelectorAll: () => [],
    };
    const canvas = {
        nodeType: 1, tagName: 'DIV', id: '', style: {},
        closest: () => null,
        querySelectorAll(selector) {
            if (selector.startsWith('img')) return [img];
            if (selector.startsWith('source')) return picture ? [source] : [];
            if (selector.startsWith('[style')) return dynamic ? [bg] : [];
            return [];
        },
    };
    const css = {
        textContent: '.court { background-image: url("' + full + '"), url("' + mini + '"); }',
    };
    const manifest = { textContent: JSON.stringify(known ? { [uuid]: mini } : {}) };
    const document = {
        readyState: 'complete',
        querySelector(selector) {
            if (selector === '.mskba-page-builder') return canvas;
            if (selector === '[data-page-builder-lqip-manifest]') return manifest;
            if (selector === 'style[data-mskba-lqip-css]') return css;
            return null;
        },
    };
    class ImageMock {
        naturalWidth = 0;
        complete = false;
        set src(value) { this.url = value; downloads.push(this); }
        get src() { return this.url; }
        decode() { return Promise.resolve(); }
        succeed() { this.naturalWidth = 320; this.complete = true; this.onload?.(); }
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
        img, bg, css, source, downloads,
        setLateBackground(value) {
            bg.style.backgroundImage = value;
            notify([{ type: 'attributes', target: bg }]);
        },
    };
}

test('server-native image starts with full src and a CSS-only LQIP underneath', async () => {
    const s = scene();
    assert.equal(s.img.src, full);
    assert.match(s.img.style.backgroundImage, /\/lqip/);
    assert.equal(s.downloads.length, 0, 'do not make a second Image() request for the same image');
    s.img.loaded();
    await Promise.resolve();
    assert.equal(s.img.src, full);
    assert.equal(s.img.style.backgroundImage, '');
    assert.equal(s.img.getAttribute('data-mskba-lqip-ready'), '1');
});

test('cached full image wins immediately instead of reverting to an LQIP on refresh', async () => {
    const s = scene({ cached: true });
    await Promise.resolve();
    assert.equal(s.img.src, full);
    assert.equal(s.img.style.backgroundImage, '');
    assert.equal(s.img.getAttribute('data-mskba-lqip-ready'), '1');
    assert.equal(s.downloads.length, 0);
});

test('failed full image does not prematurely remove a working preview', async () => {
    const s = scene();
    await Promise.resolve();
    assert.match(s.img.style.backgroundImage, /\/lqip/);
    assert.equal(s.img.getAttribute('data-mskba-lqip-ready'), null);
});

test('without a known preview the browser loads original without extra requests', () => {
    const s = scene({ known: false });
    assert.equal(s.img.src, full);
    assert.equal(s.img.style.backgroundImage, '');
    assert.equal(s.downloads.length, 0);
});

test('authored CSS background is never replaced by JS during page refresh', () => {
    const s = scene({ cached: true });
    assert.match(s.css.textContent, /url\("[^"]+"\), url\("[^"]+\/lqip"\)/);
    assert.equal(s.downloads.length, 0);
});

test('late API-provided background keeps full URL first and miniature behind', async () => {
    const s = scene({ dynamic: true });
    const original = 'linear-gradient(#000,#fff), url("' + full + '")';
    assert.match(s.bg.style.backgroundImage, new RegExp('url\\("' + full + '"\\),url\\("' + mini + '"\\)'));
    assert.equal(s.downloads.length, 1);
    s.downloads[0].succeed();
    await Promise.resolve();
    await Promise.resolve();
    assert.equal(s.bg.style.backgroundImage, original);
});

test('native picture source stays on original srcset without extra downloads', async () => {
    const s = scene({ picture: true, cached: true });
    await Promise.resolve();
    assert.equal(s.img.src, full);
    assert.equal(s.source.srcset, full);
    assert.equal(s.downloads.length, 0);
});

test('legacy mobile source placeholder upgrades to original URL', () => {
    const s = scene({ picture: true, legacySource: true });
    assert.equal(s.source.srcset, full);
});
