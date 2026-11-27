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

test('mobile catalog rebinds its sole toolbar search to the actual filter form', () => {
    const bind = js.match(/if \(toolbarSearch && activeForm\?\.id\) \{([\s\S]*?)\n            \} else \{/);
    assert.ok(bind);
    const run = new Function('toolbarSearch', 'activeForm',
        'let replacedSearch, activeSearch;' + bind[1] + ';return { replacedSearch, activeSearch };');
    const attrs = new Map([['form', 'desktop']]);
    const toolbar = { value: 'basket', setAttribute: (key, value) => attrs.set(key, value) };
    const duplicate = { value: '', disabled: false };
    const form = { id: 'mobile', querySelector: () => duplicate };
    const result = run(toolbar, form);
    assert.equal(result.activeSearch, toolbar);
    assert.equal(attrs.get('form'), 'mobile');
    assert.equal(duplicate.disabled, true);
    assert.equal(toolbar.value, 'basket');
    const venueForm = { id: 'venue-catalog-filter-form', querySelector: () => null };
    assert.equal(run(toolbar, venueForm).activeSearch, toolbar);
    assert.equal(attrs.get('form'), 'venue-catalog-filter-form');
    assert.match(js, /toolbarSearch\.setAttribute\('form', toolbarDesktopForm\)/);
    assert.match(js, /replacedSearch\.disabled = false/);
    assert.match(js, /filterPreview\.hidden = usesToolbarSearch \|\| !activeSearch/);
    assert.match(js, /toolbarSearch\.form\?\.requestSubmit\(\)/);
    assert.match(js, /filterLabel\.textContent = 'Фильтры'/);
});

test('compact toolbar stays sticky and search visible with mobile dock', () => {
    const css = read('resources/themes/mskba_app/css/catalog-controls.css');
    assert.match(css, /\.panel\.app-category-toolbar--compact\{[^}]*position:sticky;top:var\(--app-sticky-offset/);
    assert.doesNotMatch(css, /\.panel\.app-category-toolbar--compact\{position:static/);
    assert.match(css, /html\.has-app-mobile-context \.app-category-toolbar__search--desktop\{display:block\}/);
    assert.match(css, /\.panel\.app-category-toolbar--compact \.app-category-toolbar__search\{font-size:16px/);
    assert.match(styles, /\.is-toolbar-search \.app-mobile-primary__filter-preview\{display:none\}/);
});
