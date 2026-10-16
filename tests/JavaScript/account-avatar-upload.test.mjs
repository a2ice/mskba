import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const profile = readFileSync(
    new URL('../../resources/themes/mskba_app/js/account-profile.js', import.meta.url),
    'utf8',
);
const manager = readFileSync(
    new URL('../../resources/themes/mskba_app/js/avatar-manager.js', import.meta.url),
    'utf8',
);

test('profile initializes dedicated AJAX avatar manager, not native submit', () => {
    assert.match(profile, /import\s*\{\s*initAvatarManager\s*\}/);
    assert.match(profile, /initAvatarManager\(profileRoot\)/);
    assert.doesNotMatch(profile, /uploadForm\.submit\(\)/);
});

test('original files are submitted with multipart FormData and JSON response', () => {
    assert.match(manager, /new FormData\(form\)/);
    assert.match(manager, /fetch\(form\.action,/);
    assert.match(manager, /'application\/json'/);
    assert.match(manager, /'X-CSRF-TOKEN'/);
    assert.doesNotMatch(manager, /canvas|toBlob|DataTransfer/i);
});

test('all avatar forms are intercepted without page navigation', () => {
    assert.match(manager, /profileRoot\.addEventListener\('submit'/);
    assert.match(manager, /event\.preventDefault\(\)/);
    assert.match(manager, /render\(data\)/);
    assert.doesNotMatch(manager, /window\.location|location\.reload/);
});

test('client retains 10 MB limit and delegates authoritative checks to backend', () => {
    assert.match(manager, /10 \* 1024 \* 1024/);
    assert.match(manager, /errors\?\.avatar\?\.\[0\]/);
});
