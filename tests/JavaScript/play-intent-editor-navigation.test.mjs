import { test } from 'node:test';
import { strict as assert } from 'node:assert';
import { readFileSync } from 'node:fs';

const editor = readFileSync(new URL('../../resources/themes/mskba_app/js/components/PlayIntentDialog.vue', import.meta.url), 'utf8');
const account = readFileSync(new URL('../../resources/themes/mskba_app/js/play-interest-mine.js', import.meta.url), 'utf8');

test('editing a game subscription opens the original first stage', () => {
    assert.match(editor, /editingId\.value=intent\.id;step\.value='form'/);
    assert.match(editor, /v-show="step==='form'"/);
    assert.match(editor, /v-show="step==='options'"/);
    assert.match(account, /button\('Редактировать',\(\)=>editSubscription\(intent\)\)/);
    assert.match(account, /mskba:play-intent:edit/);
});

test('new delivery options belong to the second stage, not the game parameters', () => {
    const first = editor.indexOf('v-show="step===\'form\'"');
    const second = editor.indexOf('v-show="step===\'options\'"');
    assert.ok(first > -1 && second > first);
    const firstPage = editor.slice(first, second);
    const secondPage = editor.slice(second);
    assert.match(firstPage, /Название подписки/);
    assert.match(firstPage, /name="subscription_name"/);
    assert.doesNotMatch(firstPage, /Каналы уведомлений/);
    assert.match(secondPage, /Уведомлять о подходящих играх/);
    assert.match(secondPage, /Уведомлять о новостях площадки/);
    assert.match(secondPage, /Каналы уведомлений/);
    assert.match(secondPage, /Получать спецпредложения площадок/);
    assert.match(secondPage, /Показывать другим посетителям мой аватар/);
    assert.doesNotMatch(secondPage, /Оставить площадку в избранном/);
});

test('viewing a subscription is separate from editing it', () => {
    assert.match(account, /Opening "Посмотреть" stays read-only/);
    assert.match(account, /detailsEdit\.onclick=\(\)=>intent\.subscription_type==='venue'/);
});
