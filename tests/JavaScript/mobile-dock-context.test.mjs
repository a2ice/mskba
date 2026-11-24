import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = path => readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const js = read('resources/themes/mskba_app/js/mobile-primary-bar.js');
const view = read('resources/themes/mskba_app/views/partials/mobile-primary-bar.blade.php');
const styles = read('resources/themes/mskba_app/css/mobile-context-filters.css');

// Exercise the actual countSource body from the dock, not a duplicate algorithm.
const captured = js.match(/    const countSource = \(\) => \{([\s\S]*?)\n    \};/);
assert.ok(captured, 'countSource remains directly testable');
const resolve = new Function('document', 'definition',
    'const countSource = () => {' + captured[1] + '\n}; return countSource();');

function countFrom(selectors, definition = null) {
    const document = { querySelector: selector => selectors[selector] ?? null };
    return resolve(document, definition);
}

test('catalog shows server total, not the global favorites badge before it in the header', () => {
    const resultTotal = { dataset: { contextResultTotal: '18' } };
    const headerHeart = { textContent: '1' };
    const sources = {
        '[data-context-result-total]': resultTotal,
        '[data-favorites-count]': headerHeart,
    };
    assert.equal(countFrom(sources, { selector: '.app-venues-catalog__filters' }), resultTotal);
    assert.notEqual(countFrom(sources, { selector: '.app-venues-catalog__filters' }), headerHeart);
});

test('header favorite count cannot masquerade as global search results', () => {
    assert.equal(countFrom({ '[data-favorites-count]': { textContent: '1' } }), null);
});

test('AJAX counters are read only from the current account collection', () => {
    const favorites = { textContent: 'Показано элементов: 4' };
    const subscriptions = { textContent: 'Найдено подписок: 7' };
    const sources = {
        '[data-favorites-account] [data-favorites-count]': favorites,
        '[data-play-interests] [data-subscriptions-count]': subscriptions,
        '[data-favorites-count]': { textContent: '1' },
    };
    assert.equal(countFrom(sources, { selector: '.app-favorites__filter-form' }), favorites);
    assert.equal(countFrom(sources, { selector: '.app-play-interests__filters' }), subscriptions);
});

test('mobile dock keeps search icon and a distinct filter icon with chevron', () => {
    assert.match(view, /class="app-mobile-primary__search-icon"/);
    assert.match(view, /data-app-mobile-primary-filter-label>Поиск/);
    assert.match(view, /class="app-mobile-primary__filter-expand-icon"/);
    assert.match(view, /class="app-mobile-primary__filter-chevron"/);
    assert.match(styles, /app-mobile-primary__panel--filters \.app-mobile-primary__panel-header\s*\{[\s\S]*?margin: 0;[\s\S]*?padding: 8px 18px;/);
    assert.match(js, /filterLabel\.textContent = 'Поиск'/);
});
