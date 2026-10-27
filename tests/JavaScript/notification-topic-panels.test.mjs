import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const editor = readFileSync(new URL('../../resources/themes/mskba_app/js/components/PlayIntentDialog.vue', import.meta.url), 'utf8');
const layout = readFileSync(new URL('../../resources/themes/mskba_app/views/layouts/app.blade.php', import.meta.url), 'utf8');
const partial = readFileSync(new URL('../../resources/themes/mskba_app/views/pages/account/partials/contact-add-dialog.blade.php', import.meta.url), 'utf8');
const wizard = readFileSync(new URL('../../resources/themes/mskba_app/js/account-contacts-add.js', import.meta.url), 'utf8');

test('each of the three topics hides its entire channel panel when disabled', () => {
    assert.match(editor, /v-if="notifyMatches" class="app-play-dialog__notification-channels"/);
    assert.match(editor, /v-if="notifyOffers" class="app-play-dialog__notification-channels"/);
    assert.match(editor, /v-if="notifyVenueNews && chosenVenueId" class="app-play-dialog__notification-channels"/);
    assert.match(editor, /app-play-dialog__notification-topic--collapsed/);
    assert.equal((editor.match(/<strong>Каналы уведомлений<\/strong>/g) || []).length, 3);
});

test('channel choices are separate for games, offers and venue news', () => {
    assert.match(editor, /games:gameChannels,offers:offersChannels,venue:venueChannels/);
    assert.match(editor, /offers_delivery_channels:offersChannels\.value/);
    assert.match(editor, /Array\.isArray\(data\.offers_delivery_channels\)/);
    assert.match(editor, /offersChannels\.value=\['in_app'\]/);
    assert.doesNotMatch(editor, /label:'Телефон'/);
});

test('the original confirmation wizard mounts outside Contacts and opens with chosen type', () => {
    assert.match(layout, /routeIs\('account\.contacts'\)/);
    assert.match(layout, /pages\.account\.partials\.contact-add-dialog/);
    assert.match(partial, /contactDialogOnly/);
    assert.match(wizard, /window\.addEventListener\('mskba:contact:add', event => open\(event\.detail\?\.type \|\| 'email'\)\)/);
    assert.match(editor, /detail:\{type:key\}/);
    assert.match(wizard, /mskba:contact:updated/);
    assert.match(editor, /mskba:contact:updated',refreshChannels/);
});

test('disabled external channels have context actions rather than explanation paragraphs', () => {
    assert.equal((editor.match(/channelAction\(ch\.key\)==='add_contact'/g)||[]).length,3);
    assert.doesNotMatch(editor, /channelReason\(ch\.key\)/);
    assert.doesNotMatch(editor, /Добавить Телефон/);
});
