import { test } from 'node:test';
import { strict as assert } from 'node:assert';
import { readFileSync } from 'node:fs';

const editor = readFileSync(new URL('../../resources/themes/mskba_app/js/components/PlayIntentDialog.vue', import.meta.url), 'utf8');
const account = readFileSync(new URL('../../resources/themes/mskba_app/js/play-interest-mine.js', import.meta.url), 'utf8');
const hearts = readFileSync(new URL('../../resources/themes/mskba_app/js/venue-favorites.js', import.meta.url), 'utf8');
const bootstrap = readFileSync(new URL('../../resources/themes/mskba_app/js/app.js', import.meta.url), 'utf8');

test('all entry points use the same mounted context-aware popup', () => {
    assert.match(bootstrap, /createApp\(PlayIntentDialog/);
    assert.match(hearts, /mskba:subscription-dialog:open/);
    assert.match(editor, /mskba:subscription-dialog:open/);
    assert.match(editor, /contextSource\.value='favorite'/);
    assert.match(editor, /contextSource\.value='play'/);
    assert.match(editor, /contextSource\.value='edit'/);
});

test('clicking a heart saves its actual entity before opening optional actions', () => {
    assert.match(hearts, /request\(endpoints\.toggle,'POST',\{type:'venue',id\}\)/);
    assert.match(hearts, /request\(endpoints\.toggle,'POST',\{type,id\}\)/);
    assert.match(hearts, /if\(result\.state==='active'\)/);
    assert.match(hearts, /contextNames\[type\]/);
    assert.match(editor, /favoriteTitle\.value/);
    assert.match(editor, /contextSource==='favorite'/);
    assert.match(editor, /saveFavoriteOnly/);
    assert.match(editor, /playOpen\.value=false;favoriteOpen\.value=true/);
});

test('game creation keeps original first page and adds optional favorites only on page two', () => {
    assert.match(account, /button\('Посмотреть',\(\)=>editSubscription\(intent\)\)/);
    assert.match(editor, /editingId\.value=intent\.id;step\.value='form'/);
    const first = editor.indexOf('v-show="step===\'form\'"');
    const second = editor.indexOf('v-show="step===\'options\'');
    assert.ok(first>-1 && second>first);
    const stepOne = editor.slice(first,second);
    const stepTwo = editor.slice(second);
    assert.match(stepOne,/Название подписки/);
    assert.match(stepOne,/Выбранная площадка/);
    assert.doesNotMatch(stepOne,/Добавить площадку в избранное/);
    assert.match(stepTwo,/Добавить площадку в избранное/);
    assert.match(stepTwo,/favoriteExtraVisible/);
    assert.match(stepTwo,/Уведомлять о подходящих играх/);
    assert.match(stepTwo,/Уведомлять о новостях площадки/);
    assert.match(stepTwo,/Каналы уведомлений/);
    assert.doesNotMatch(stepTwo,/Оставить площадку в избранном/);
});

test('favorite and subscription are independent async actions, and other entity types show no game fields', () => {
    assert.match(editor, /contextType\.value==='venue'/);
    assert.match(editor, /supportsPlay && playOpen/);
    assert.match(editor, /success\.value=result\.data/);
    assert.match(editor, /favoriteRetryNeeded\.value=!await applyFavorite\(\)/);
    assert.match(editor, /favoriteExtraVisible\.value && favoriteSelected\.value/);
    assert.match(editor, /favoriteState\.value==='active'/);
    assert.match(editor, /contextSource==='favorite'/);
    assert.match(hearts, /contextNames/);
});
