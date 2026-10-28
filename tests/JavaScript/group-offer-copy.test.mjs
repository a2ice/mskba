import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/themes/mskba_app/js/group-offer-copy.js', import.meta.url), 'utf8');

test('group offer copy sends only the code to clipboard and confirms success', async () => {
    let listener;
    const copied = [];
    const result = { textContent: '' };
    const card = { querySelector: selector =>
        selector === '[data-group-offer-code]'
            ? { textContent: ' PROMO2026 ' }
            : selector === '[data-group-offer-copy-result]' ? result : null };
    const trigger = { closest: () => card };
    const target = { closest: () => trigger };
    runInNewContext(source, {
        document: { addEventListener: (type, callback) => {
            assert.equal(type, 'click');
            listener = callback;
        } },
        navigator: { clipboard: { writeText: async value => copied.push(value) } },
    });
    await listener({ target });
    assert.deepEqual(copied, ['PROMO2026']);
    assert.equal(result.textContent, 'Скопировано');
});

test('group offer copy reports clipboard refusal without claiming success', async () => {
    let listener;
    const result = { textContent: '' };
    const card = { querySelector: selector => selector === '[data-group-offer-code]'
        ? { textContent: 'TEST2026' } : result };
    runInNewContext(source, {
        document: { addEventListener: (_type, callback) => { listener = callback; } },
        navigator: { clipboard: { writeText: async () => { throw new Error('blocked'); } } },
    });
    await listener({ target: { closest: () => ({ closest: () => card }) } });
    assert.match(result.textContent, /Не удалось скопировать/);
});
