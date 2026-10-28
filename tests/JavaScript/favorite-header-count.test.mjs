import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { normalizeFavoriteCount, formatFavoriteCount, canApplyFavoriteSync } from
    '../../resources/themes/mskba_app/js/favorite-counts-core.js';

const source = path => readFileSync(new URL(path, import.meta.url), 'utf8');

test('favorite counter accepts only nonnegative safe integers and formats compactly', () => {
    assert.equal(normalizeFavoriteCount(null), null);
    assert.equal(normalizeFavoriteCount(''), null);
    assert.equal(normalizeFavoriteCount(-1), null);
    assert.equal(normalizeFavoriteCount('1.5'), null);
    assert.equal(normalizeFavoriteCount('not-a-number'), null);
    assert.equal(normalizeFavoriteCount(0), 0);
    assert.equal(normalizeFavoriteCount('19'), 19);
    assert.equal(normalizeFavoriteCount(Number.MAX_SAFE_INTEGER + 1), null);
    assert.equal(formatFavoriteCount(0), '0');
    assert.equal(formatFavoriteCount(99), '99');
    assert.equal(formatFavoriteCount(100), '99+');
});

test('stale count-sync responses cannot overwrite a newer action or websocket event', () => {
    assert.equal(canApplyFavoriteSync(2, 3, 7, 7), false);
    assert.equal(canApplyFavoriteSync(2, 2, 7, 8), false);
    assert.equal(canApplyFavoriteSync(2, 2, 8, 8), true);
});

test('the header count reuses the private Reverb channel and reconciles other tabs', () => {
    const js = source('../../resources/themes/mskba_app/js/favorite-counts.js');
    const app = source('../../resources/themes/mskba_app/js/app.js');
    const blade = source('../../resources/themes/mskba_app/views/partials/header.blade.php');
    const favorites = source('../../resources/themes/mskba_app/js/venue-favorites.js');
    const account = source('../../resources/themes/mskba_app/js/account-favorites.js');
    const vue = source('../../resources/themes/mskba_app/js/components/PlayIntentDialog.vue');
    const auth = source('../../resources/themes/mskba_app/js/components/AuthDialog.vue');
    assert.match(app, /import '.\/favorite-counts.js'/);
    assert.match(js, /\.favorites\.count-updated/);
    assert.match(js, /BroadcastChannel/);
    assert.match(js, /visibilitychange/);
    assert.match(js, /realtime-state/);
    assert.match(js, /cache: 'no-store'/);
    assert.match(blade, /data-header-favorites/);
    assert.match(blade, /data-auth-redirect/);
    assert.match(auth, /redirectAfterAuth/);
    for (const consumer of [favorites, account, vue]) {
        assert.match(consumer, /mskba:favorites:changed/);
    }
});

test('header favorites display an inline heart and plain counter before login/avatar', () => {
    const css = source('../../resources/themes/mskba_app/css/header.css');
    const blade = source('../../resources/themes/mskba_app/views/partials/header.blade.php');
    const extract = selector => css.match(new RegExp('\\.' + selector + '\\s*\\{([^}]*)\\}'))?.[1] || '';
    const actions = extract('app-header-actions');
    const link = extract('app-header-favorites');
    const count = extract('app-header-favorites__count');

    assert.match(actions, /align-items:\s*center/);
    assert.match(link, /display:\s*inline-flex/);
    assert.match(link, /align-items:\s*center/);
    assert.match(link, /gap:\s*6px/);
    assert.match(link, /min-height:\s*44px/);
    assert.match(count, /display:\s*inline-block/);
    assert.match(count, /color:\s*currentColor/);
    assert.doesNotMatch(count, /position:\s*absolute|border:|border-radius:|background:/);
    assert.match(css, /\.app-header-favorites__count\[hidden\]\s*\{\s*display:\s*none/);
    assert.ok(blade.indexOf('class="app-header-favorites"') > -1);
    assert.ok(blade.indexOf('class="app-header-favorites"') < blade.indexOf('class="app-header-login"'));
    assert.ok(blade.indexOf('class="app-header-favorites"') < blade.indexOf("'app-header-account'"));
});
