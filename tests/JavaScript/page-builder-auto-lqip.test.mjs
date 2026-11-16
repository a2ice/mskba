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

function scene({ known = true, dynamic = false, picture = false, lazy = false, withPreloader = false } = {}) {
    const downloads = [];
    let notify = null;
    let domReady = null;
    let pageLoad = null;
    let upgradeTimer = null;
    let imageLoaded = null;
    let layerNotify = null;
    const layerParent = {};
    const layer = { isConnected: true, parentElement: layerParent };
    const attrs = new Map([
        ['src', known ? mini : full],
        ...(known ? [['data-mskba-lqip-full-src', full], ['data-mskba-lqip-preview-src', mini]] : []),
    ]);
    const style = {
        backgroundImage: '', backgroundSize: '', backgroundPosition: '', backgroundRepeat: '',
        removeProperty(name) { this[name.replace(/-([a-z])/g, (_, c) => c.toUpperCase())] = ''; },
    };
    const sourceAttrs = new Map(picture && known
        ? [['data-mskba-lqip-full-srcset', full]] : []);
    const source = {
        tagName: 'SOURCE', nodeType: 1, isConnected: true,
        media: '(max-width: 640px)', srcset: known ? mini : full,
        getAttribute: name => sourceAttrs.get(name) || null,
        removeAttribute: name => sourceAttrs.delete(name),
        closest: () => null,
    };
    const wrapper = {
        querySelectorAll: selector => selector.startsWith('source') ? [source] : [],
        querySelector: selector => selector.startsWith('img') ? img : null,
    };
    const img = {
        tagName: 'IMG', nodeType: 1, loading: lazy ? 'lazy' : 'eager',
        isConnected: true, complete: true, naturalWidth: 16, style,
        getAttribute: name => attrs.get(name) || null,
        setAttribute(name, value) { attrs.set(name, value); },
        removeAttribute(name) { attrs.delete(name); },
        hasAttribute: name => attrs.has(name),
        closest: selector => selector === 'picture' && picture ? wrapper : null,
        set src(value) { attrs.set('src', value); this.complete = false; },
        get src() { return attrs.get('src'); },
        set srcset(value) { attrs.set('srcset', value); },
        decode: () => Promise.resolve(),
        addEventListener(type, cb) { if (type === 'load') imageLoaded = cb; },
        loaded() { this.complete = true; this.naturalWidth = 320; imageLoaded?.(); },
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
            if (selector.startsWith('[style')) return dynamic ? [bg] : [];
            return [];
        },
    };
    const css = { textContent: '.court { background-image: url("' + mini + '"); }' };
    const manifest = { textContent: JSON.stringify(known ? { [uuid]: mini } : {}) };
    const document = {
        readyState: 'loading',
        addEventListener(type, cb) { if (type === 'DOMContentLoaded') domReady = cb; },
        querySelector(selector) {
            if (selector === '.mskba-page-builder') return canvas;
            if (selector === '[data-page-builder-lqip-manifest]') return manifest;
            if (selector === 'style[data-mskba-lqip-css]') return css;
            if (selector === '.mskba-v2-stage__loading') return withPreloader ? layer : null;
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
        constructor(callback) { this.callback = callback; notify = callback; }
        observe(target) { if (target === layerParent) layerNotify = this.callback; }
        disconnect() {}
    }
    const window = {
        location: { href: 'https://dev.mskba.ru/#venues', origin: 'https://dev.mskba.ru' },
        matchMedia: () => ({ matches: true }),
        addEventListener(type, cb) { if (type === 'load') pageLoad = cb; },
        setTimeout(cb) { upgradeTimer = cb; return 1; },
        clearTimeout() {},
    };
    runInNewContext(script, { document, window, URL, Image: ImageMock,
        MutationObserver: MutationObserverMock, console });
    domReady();
    return {
        img, bg, css, source, downloads,
        initialLoad() {
            pageLoad();
            if (!withPreloader) upgradeTimer();
        },
        dismissLayer() {
            layer.isConnected = false;
            layerNotify?.([]);
            upgradeTimer();
        },
        setLateBackground(value) {
            bg.style.backgroundImage = value;
            notify([{ type: 'attributes', target: bg }]);
        },
    };
}

test('first paint uses LQIP and does not request originals before page load', () => {
    const s = scene({ picture: true });
    assert.equal(s.img.src, mini);
    assert.equal(s.source.srcset, mini);
    assert.match(s.css.textContent, /\/lqip/);
    assert.doesNotMatch(s.css.textContent, new RegExp('url\\("' + full + '"\\)'));
    assert.equal(s.downloads.length, 0);
    assert.equal(s.img.getAttribute('data-mskba-lqip-ready'), null);
});

test('original downloads after initial load and replaces miniature only after decoding', async () => {
    const s = scene({ picture: true });
    s.initialLoad();
    assert.equal(s.downloads.length, 2, 'CSS and img originals are fetched after load');
    assert.equal(s.downloads[1].src, full);
    assert.equal(s.img.src, mini);
    assert.equal(s.source.srcset, mini);

    s.downloads[1].succeed();
    await Promise.resolve();
    await Promise.resolve();
    assert.equal(s.img.src, full);
    assert.equal(s.source.srcset, full);
    assert.equal(s.img.getAttribute('data-mskba-lqip-ready'), null);
    s.img.loaded();
    await Promise.resolve();
    await Promise.resolve();
    assert.equal(s.img.getAttribute('data-mskba-lqip-ready'), '1');
});

test('failed original leaves working LQIP in place', () => {
    const s = scene();
    s.initialLoad();
    s.downloads[1].fail();
    assert.equal(s.img.src, mini);
    assert.equal(s.img.getAttribute('data-mskba-lqip-ready'), null);
});

test('without a known preview original loads normally', () => {
    const s = scene({ known: false });
    s.initialLoad();
    assert.equal(s.img.src, full);
    assert.equal(s.downloads.length, 0);
});

test('published CSS background upgrades only after loading layer can finish', async () => {
    const s = scene();
    assert.equal(s.css.textContent, '.court { background-image: url("' + mini + '"); }');
    s.initialLoad();
    assert.equal(s.css.textContent, '.court { background-image: url("' + mini + '"); }');
    s.downloads[0].succeed();
    await Promise.resolve();
    await Promise.resolve();
    assert.equal(s.css.textContent, '.court { background-image: url("' + full + '"); }');
});

test('late API background upgrades after load without a premature network request', async () => {
    const s = scene({ dynamic: true });
    assert.equal(s.downloads.length, 0);
    s.initialLoad();
    assert.equal(s.downloads.length, 3, 'CSS, img and dynamic background upgrades');
    assert.match(s.bg.style.backgroundImage, new RegExp('url\\("' + full + '"\\),url\\("' + mini + '"\\)'));
    s.downloads[2].succeed();
    await Promise.resolve();
    await Promise.resolve();
    assert.equal(s.bg.style.backgroundImage, 'linear-gradient(#000,#fff), url("' + full + '")');
});

test('a dynamic original image with no generated miniature is untouched', () => {
    const s = scene({ known: false });
    s.initialLoad();
    assert.equal(s.img.src, full);
});

test('initial load callback cannot start originals until its deferred handoff', () => {
    const s = scene();
    assert.equal(s.downloads.length, 0);
    s.initialLoad();
    assert.equal(s.downloads.length, 2);
});

test('full-resolution assets do not start until the real homepage layer is removed', () => {
    const s = scene({ withPreloader: true });
    s.initialLoad();
    assert.equal(s.downloads.length, 0, 'window.load alone does not start originals');
    s.dismissLayer();
    assert.equal(s.downloads.length, 2, 'full images start after removing the stage layer');
});
