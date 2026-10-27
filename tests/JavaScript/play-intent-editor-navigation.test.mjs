import { test } from 'node:test';
import { strict as assert } from 'node:assert';
import { readFileSync } from 'node:fs';

const editor = readFileSync(new URL('../../resources/themes/mskba_app/js/components/PlayIntentDialog.vue', import.meta.url), 'utf8');
const account = readFileSync(new URL('../../resources/themes/mskba_app/js/play-interest-mine.js', import.meta.url), 'utf8');
const hearts = readFileSync(new URL('../../resources/themes/mskba_app/js/venue-favorites.js', import.meta.url), 'utf8');
const bootstrap = readFileSync(new URL('../../resources/themes/mskba_app/js/app.js', import.meta.url), 'utf8');

test('both entry points open the same modal component', () => {
    assert.match(editor, /mskba:subscription-dialog:open/);
    assert.match(hearts, /mskba:subscription-dialog:open/);
    assert.match(bootstrap, /createApp\(PlayIntentDialog/);
    assert.match(editor, /contextSource\.value='favorite'/);
    assert.match(editor, /contextSource\.value='play'/);
});

test('heart opens favorite first and does not create a game subscription automatically', () => {
    assert.match(editor, /playOpen\.value=false;favoriteOpen\.value=true/);
    assert.match(editor, /v-model="favoriteSelected"/);
    assert.match(editor, /v-model="playOpen"/);
    assert.match(editor, /saveFavoriteOnly\(\)/);
    assert.doesNotMatch(hearts, /async function toggle\(id\)/);
});

test('non-venue favorites never show the basketball game editor', () => {
    assert.match(editor, /contextType\.value==='venue'/);
    assert.match(editor, /supportsPlay && playOpen/);
    assert.match(bootstrap, /data-favorite-type/);
    assert.match(editor, /applyFavorite\(\)/);
});

test('Посмотреть opens the original two-step subscription editor', () => {
    assert.match(account, /button\('Посмотреть',\(\)=>editSubscription\(intent\)\)/);
    assert.match(editor, /editingId\.value=intent\.id;step\.value='form'/);
    assert.match(editor, /v-show="step==='form'"/);
    assert.match(editor, /v-show="step==='options' && supportsPlay && playOpen"/);
    const first = editor.indexOf('v-show="step===\'form\'"');
    const second = editor.indexOf('v-show="step===\'options\'');
    assert.ok(first > 0 && second > first);
    const stepOne=editor.slice(first,second),stepTwo=editor.slice(second);
    assert.match(stepOne,/Название подписки/);
    assert.match(stepOne,/Выбранная площадка/);
    assert.match(stepTwo,/Уведомлять о подходящих играх/);
    assert.match(stepTwo,/Уведомлять о новостях площадки/);
    assert.match(stepTwo,/Каналы уведомлений/);
    assert.doesNotMatch(stepTwo,/Оставить площадку в избранном/);
});
