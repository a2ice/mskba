import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const theme = 'resources/themes/mskba_app/';
const read = path => readFileSync(new URL('../../' + path, import.meta.url), 'utf8');

const subs = read(theme + 'views/pages/account/play-interests.blade.php');
const fav = read(theme + 'views/pages/account/favorites.blade.php');
const games = read(theme + 'views/pages/account/my-games.blade.php');
const teams = read(theme + 'views/pages/account/teams.blade.php');
const subscriptionsJs = read(theme + 'js/play-interest-mine.js');
const favoritesJs = read(theme + 'js/account-favorites.js');
const styles = read(theme + 'css/account-collection.css');
const app = read(theme + 'js/app.js');

test('subscriptions preserve all filters, editing, pausing and restore while using separate result cards', () => {
    assert.match(subs, /class="app-play-interests account-collection"/);
    assert.match(subs, /class="app-play-interests__filters account-collection__filters"/);
    assert.match(subs, /data-subscriptions-count/);
    assert.match(subs, /data-play-interests-list/);
    assert.match(subscriptionsJs, /app-play-interests__item account-collection__card/);
    assert.match(subscriptionsJs, /account-collection__status/);
    assert.match(subscriptionsJs, /button\('Посмотреть',\(\)=>editSubscription\(intent\)\)/);
    assert.match(subscriptionsJs, /\/pause','PATCH'/);
    assert.match(subscriptionsJs, /\/resume','PATCH'/);
    assert.match(subscriptionsJs, /restoreSubscription\(intent/);
    assert.match(subscriptionsJs, /renderPagination\(response.meta\)/);
});

test('favorites use accessible filters and retain trashed state and restore behavior', () => {
    assert.match(fav, /data-favorites-filters/);
    assert.match(fav, /select id="account-favorites-type" data-favorites-type/);
    assert.match(fav, /select id="account-favorites-state" data-favorites-state/);
    assert.match(fav, /option value="trashed">Корзина/);
    assert.match(fav, /data-favorites-count/);
    assert.match(favoritesJs, /typeSelect.addEventListener\('change'/);
    assert.match(favoritesJs, /stateSelect.addEventListener\('change'/);
    assert.match(favoritesJs, /data-favorites-reset/);
    assert.match(favoritesJs, /app-favorites__item account-collection__card/);
    assert.match(favoritesJs, /state === 'active' \? 'Убрать в корзину' : 'Восстановить'/);
    assert.match(favoritesJs, /sequence !== loadSequence/);
    assert.match(favoritesJs, /favorites:changed/);
});

test('games and teams share the cards, including management and invitation sections', () => {
    assert.match(games, /account-collection--games/);
    assert.match(games, /app-my-games__filters account-collection__tabs/);
    assert.match(games, /app-my-games__card account-collection__card/);
    assert.match(teams, /account-collection--teams/);
    assert.match(teams, /app-team-filters account-collection__filters/);
    assert.match(teams, /app-team-card account-collection__card/);
    assert.match(teams, /teams.invitations.respond/);
    assert.match(teams, /data-team-placeholder-action="find"/);
});

test('shared stylesheet is imported and restricted to opt-in account sections', () => {
    assert.match(app, /import '\.\.\/css\/account-collection\.css'/);
    assert.match(styles, /\.account-collection__filters\{/);
    assert.match(styles, /\.account-collection__card\{/);
    assert.match(styles, /\.account-collection__status\.is-active/);
    assert.match(styles, /@media\(max-width:560px\)/);
    assert.doesNotMatch(read(theme + 'views/pages/account/notifications.blade.php'), /account-collection/);
});
