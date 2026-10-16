import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const source = readFileSync(
    new URL('../../resources/themes/mskba_app/js/account-profile.js', import.meta.url),
    'utf8',
).replace(/^import .+;$/gm, '');

function selectFile(file) {
    const handlers = new Map();
    const state = { hidden: true, textContent: '' };
    const input = {
        files: [file],
        value: 'chosen-file',
        addEventListener(name, callback) { handlers.set(name, callback); },
    };
    const uploadForm = {
        submitted: 0, busy: false,
        querySelector(selector) { return selector === 'input[type=file]' ? input : null; },
        setAttribute(name, value) { if (name === 'aria-busy') this.busy = value === 'true'; },
        submit() { this.submitted++; },
    };
    const profileRoot = {
        querySelector(selector) {
            if (selector === '[data-profile-avatar-upload]') return uploadForm;
            if (selector === '[data-profile-avatar-upload-status]') return state;
            return null;
        },
    };
    // Intentionally no Canvas, WebP encoder, DataTransfer, or File constructor.
    // Safari must not require any of them just to submit a valid image.
    runInNewContext(source, {
        document: {
            querySelector(selector) {
                return selector === '[data-account-profile]' ? profileRoot : null;
            },
        },
    });

    handlers.get('change')({ target: input });
    return { uploadForm, state, input };
}

test('a 2.6 MB PNG is submitted as the original file without Canvas', () => {
    const file = { name: 'logo.png', type: 'image/png', size: 2637631 };
    const { uploadForm, state, input } = selectFile(file);
    assert.equal(uploadForm.submitted, 1);
    assert.equal(uploadForm.busy, true);
    assert.equal(state.textContent, 'Загружаем…');
    assert.equal(state.hidden, false);
    assert.strictEqual(input.files[0], file);
});

test('an original JPEG at the 10 MiB boundary is submitted unchanged', () => {
    const file = { name: 'photo.jpg', type: 'image/jpeg', size: 10 * 1024 * 1024 };
    const { uploadForm, input } = selectFile(file);
    assert.equal(uploadForm.submitted, 1);
    assert.strictEqual(input.files[0], file);
});

test('files over 10 MiB are rejected before multipart POST', () => {
    const file = { name: 'huge.png', type: 'image/png', size: 10 * 1024 * 1024 + 1 };
    const { uploadForm, state, input } = selectFile(file);
    assert.equal(uploadForm.submitted, 0);
    assert.equal(uploadForm.busy, false);
    assert.equal(input.value, '');
    assert.equal(state.textContent, 'Файл аватара должен быть не больше 10 МБ.');
});

test('unsupported type reaches authoritative Laravel validation', () => {
    const file = { name: 'unsupported.svg', type: 'image/svg+xml', size: 128 };
    const { uploadForm } = selectFile(file);
    assert.equal(uploadForm.submitted, 1);
});
