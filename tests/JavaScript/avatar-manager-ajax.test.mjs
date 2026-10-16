import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const source = readFileSync(
    new URL('../../resources/themes/mskba_app/js/avatar-manager.js', import.meta.url),
    'utf8',
).replace('export function initAvatarManager', 'function initAvatarManager');

function fixture() {
    const elements = [];
    const events = new Map();
    const calls = [];
    const file = { name: 'photo.png', size: 2637631, type: 'image/png' };
    const elt = name => ({
        name, children: [], dataset: {}, hidden: false, disabled: false, value: '',
        setAttribute(key, value) { this[key] = value; },
        append(...args) { this.children.push(...args); },
        replaceChildren(...args) { this.children = args; },
        querySelectorAll() { return []; },
        addEventListener(type, fn) { events.set(name + ':' + type, fn); },
    });
    const input = elt('file');
    input.files = [file];
    const csrfInput = { value: 'csrf' };
    const upload = elt('upload');
    upload.action = '/account/avatar';
    upload.querySelector = selector => ({
        'input[type=file]': input,
        'input[name="_token"]': csrfInput,
    })[selector] || null;
    const preview = elt('preview');
    const library = elt('library');
    const state = elt('status');
    const header = elt('header');
    const root = elt('root');
    root.querySelector = selector => ({
        '[data-profile-avatar-upload]': upload,
        '[data-profile-avatar-preview]': preview,
        '[data-profile-avatar-library]': library,
        '[data-profile-avatar-upload-status]': state,
    })[selector] || null;
    root.contains = () => true;
    const responses = [];
    class FakeFormData {
        constructor(form) {
            this.form = form;
            this.file = form === upload ? file : null;
        }
    }
    runInNewContext(source + '\ninitAvatarManager(root);', {
        root, FormData: FakeFormData,
        document: {
            createElement: elt,
            createElementNS: (_ns, name) => elt(name),
            querySelectorAll: selector => selector.includes('app-user-avatar__visual') ? [header] : [],
        },
        fetch: async (url, options) => {
            calls.push({ url, options });
            return responses.shift();
        },
    });
    return { file, events, calls, upload, input, preview, library, state, header, root, responses };
}
const avatar = (id, active) => ({
    id, url: '/storage/avatars/' + id + '.webp', is_featured: active,
    activate_url: '/account/avatar/' + id + '/activate',
    delete_url: '/account/avatar/' + id,
});
const success = (avatars, selected) => ({
    ok: true, json: async () => ({
        message: 'Аватар обновлён.', avatar_url: selected, avatars,
    }),
});
const flush = () => new Promise(resolve => setImmediate(resolve));

test('upload sends original 2.6MB PNG with AJAX and updates page/header', async () => {
    const f = fixture();
    f.responses.push(success([avatar(1, true)], avatar(1, true).url));
    f.events.get('file:change')();
    await flush();
    assert.equal(f.calls.length, 1);
    assert.equal(f.calls[0].url, '/account/avatar');
    assert.equal(f.calls[0].options.method, 'POST');
    assert.equal(f.calls[0].options.headers.Accept, 'application/json');
    assert.equal(f.calls[0].options.body.file, f.file);
    assert.equal(f.preview.children[0].src, '/storage/avatars/1.webp');
    assert.equal(f.header.children[0].src, '/storage/avatars/1.webp');
    assert.equal(f.library.children.length, 1);
    assert.equal(f.library.hidden, false);
    assert.equal(f.state.textContent, 'Аватар обновлён.');
});

test('activate/delete use delegated AJAX, update fallback and keep page open', async () => {
    const f = fixture();
    f.responses.push(success([avatar(1, true)], avatar(1, true).url));
    const form = { action: '/account/avatar/1', closest: () => form };
    let prevented = 0;
    f.events.get('root:submit')({ target: form, preventDefault() { prevented++; } });
    await flush();
    assert.equal(prevented, 1);
    assert.equal(f.calls[0].options.method, 'POST');
    assert.equal(f.preview.children[0].src, avatar(1, true).url);

    f.responses.push(success([], null));
    f.events.get('root:submit')({ target: form, preventDefault() { prevented++; } });
    await flush();
    assert.equal(prevented, 2);
    assert.equal(f.library.hidden, true);
    assert.equal(f.preview.children[0].name, 'svg');
    assert.equal(f.header.children[0].name, 'svg');
});

test('JSON server errors stay inline without navigation', async () => {
    const f = fixture();
    f.responses.push({
        ok: false, status: 422,
        json: async () => ({ errors: { avatar: ['Поддерживаются только JPEG, PNG и WebP.'] } }),
    });
    f.events.get('file:change')();
    await flush();
    assert.equal(f.state.dataset.error, 'true');
    assert.equal(f.state.textContent, 'Поддерживаются только JPEG, PNG и WebP.');
    assert.equal(f.upload['aria-busy'], 'false');
    assert.equal(f.calls.length, 1);
});
