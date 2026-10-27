import { test } from 'node:test';
import { strict as assert } from 'node:assert';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

// Verify the actual heart-label function without executing browser-only DOM code.
const source = readFileSync(new URL('../../resources/themes/mskba_app/js/venue-favorites.js', import.meta.url), 'utf8');
const declaration = source.match(/^\s*const getName = \(state\) => [^;]+;/m);
assert.ok(declaration, 'favorite label function must exist');
const getName = runInNewContext(`(() => { ${declaration[0]} return getName; })()`);

test('basket and never-saved venue hearts both read Добавить в избранное', () => {
    assert.equal(getName('trashed'), 'Добавить в избранное');
    assert.equal(getName('none'), 'Добавить в избранное');
});

test('active heart keeps Убрать из избранного and accessible labels', () => {
    assert.equal(getName('active'), 'Убрать из избранного');
    assert.match(source, /btn\.setAttribute\('aria-label',getName\(state\)\)/);
    assert.match(source, /btn\.title=state==='active'\?'Добавлено в избранное':getName\(state\)/);
    assert.match(source, /btn\.setAttribute\('aria-pressed',String\(state==='active'\)\)/);
});

test('public heart still toggles original favorite record rather than creating a duplicate', () => {
    assert.match(source, /const result=await request\(endpoints\.toggle,'POST',\{type:'venue',id\}\)/);
});
