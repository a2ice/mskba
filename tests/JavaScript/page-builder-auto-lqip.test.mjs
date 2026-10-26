import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import test from 'node:test';

const script = readFileSync(
    new URL('../../resources/themes/mskba_app/js/features/page-builder-auto-lqip.js', import.meta.url),
    'utf8',
);
const asset = '60d4d65b-308c-40aa-8466-158466632107';

function scene({ known = true, existing = false, background = true } = {}) {
    const imageEvents = new Map();
    let backgroundReady = background;
    let observerCallback = null;
    const previews = [];
    const classes = () => {
        const values = new Set();
        return {
            add: name => values.add(name),
            contains: name => values.has(name),
        };
    };
    const container = {
        contains: () => true,
    };
    const img = {
        tagName: 'IMG',
        nodeType: 1,
        src: 'https://dev.mskba.ru/media/site/' + asset,
        currentSrc: 'https://dev.mskba.ru/media/site/' + asset,
        complete: false,
        naturalWidth: 0,
        loading: 'eager',
        isConnected: true,
        parentElement: container,
        offsetParent: container,
        offsetLeft: 17,
        offsetTop: 20,
        offsetWidth: 150,
        offsetHeight: 90,
        getBoundingClientRect: () => ({ width: 150, height: 90 }),
        hasAttribute: name => existing && name === 'data-site-media-lqip',
        closest: () => null,
        before: overlay => previews.push(overlay),
        addEventListener: (name, cb) => imageEvents.set(name, cb),
        decode: () => Promise.resolve(),
    };
    const bgClasses = classes();
    const bg = {
        tagName: 'SECTION',
        nodeType: 1,
        classList: bgClasses,
        closest: () => null,
        getBoundingClientRect: () => ({ width: 500, height: 300 }),
        prepend: preview => previews.push(preview),
    };
    const canvas = {
        tagName: 'DIV',
        nodeType: 1,
        classList: classes(),
        querySelectorAll: selector => selector === 'img' ? [img] : [img, bg],
    };
    const manifest = {
        textContent: JSON.stringify(known ? { [asset]: '/media/site/' + asset + '/lqip' } : {}),
    };
    const created = [];
    const document = {
        readyState: 'complete',
        querySelector: sel => sel === '.mskba-page-builder' ? canvas :
            sel === '[data-page-builder-lqip-manifest]' ? manifest : null,
        createElement: tagName => {
            const el = {
                tagName, style: {}, classList: classes(),
                attrs: {}, src: '',
                setAttribute(name, value) { this.attrs[name] = value; },
                addEventListener(name, handler) { this.listeners[name] = handler; },
                listeners: {},
                remove() { this.removed = true; },
            };
            created.push(el);
            return el;
        },
    };
    const window = {
        location: { href: 'https://dev.mskba.ru/', origin: 'https://dev.mskba.ru' },
        matchMedia: () => ({ matches: false }),
        requestAnimationFrame: callback => callback(),
        getComputedStyle: element => element === img ? {
            objectFit: 'contain', objectPosition: 'center', borderRadius: '3px',
            transform: 'none', transformOrigin: 'center', zIndex: 'auto',
        } : {
            backgroundImage: element === bg && backgroundReady
                ? 'linear-gradient(90deg, transparent, #fff), url("https://dev.mskba.ru/media/site/' + asset + '")'
                : 'none',
            backgroundSize: 'cover', backgroundPosition: 'center',
            backgroundRepeat: 'no-repeat', backgroundClip: 'border-box',
            backgroundOrigin: 'padding-box', borderRadius: '12px', position: 'relative',
        },
    };
    class ImageMock {
        complete = false;
        naturalWidth = 0;
        decode() { return Promise.resolve(); }
        set src(v) { this.url = v; }
    }
    class MutationObserverMock {
        constructor(callback) { observerCallback = callback; }
        observe() {}
        disconnect() {}
    }
    runInNewContext(script, { document, window, URL, Image: ImageMock,
        MutationObserver: MutationObserverMock, console });
    return {
        img, bg, bgClasses, imageEvents, previews, created,
        activateBackground() {
            backgroundReady = true;
            observerCallback([{ type: 'attributes', target: bg }]);
        },
    };
}

test('Page Builder LQIP finds matching HTML img and CSS background without changing editor sources', async () => {
    const s = scene();
    assert.equal(s.previews.length, 2);
    assert.equal(s.previews[0].src, '/media/site/' + asset + '/lqip');
    assert.equal(s.img.src, 'https://dev.mskba.ru/media/site/' + asset);
    assert.equal(s.previews[0].style.position, 'absolute');
    assert.match(s.previews[1].style.backgroundImage, /\/lqip/);
    assert.equal(s.bgClasses.contains('has-pb-site-lqip-background'), true);
    s.imageEvents.get('load')();
    await Promise.resolve();
    await Promise.resolve();
    assert.equal(s.previews[0].classList.contains('is-lqip-replaced'), true);
});

test('missing LQIP yields no placeholders or extra requests', () => {
    const s = scene({ known: false });
    assert.equal(s.previews.length, 0);
    assert.equal(s.bgClasses.contains('has-pb-site-lqip-background'), false);
});

test('already enhanced Hero images and absent CSS backgrounds are left alone', () => {
    const s = scene({ existing: true, background: false });
    assert.equal(s.previews.length, 0);
});

test('late JS-assigned tournament background becomes LQIP-capable after DOM mutation', () => {
    const s = scene({ existing: true, background: false });
    assert.equal(s.previews.length, 0);
    s.activateBackground();
    assert.equal(s.previews.length, 1);
    assert.match(s.previews[0].style.backgroundImage, /\/lqip/);
    assert.equal(s.bgClasses.contains('has-pb-site-lqip-background'), true);
});
