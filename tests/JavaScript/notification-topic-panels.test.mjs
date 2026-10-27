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
    assert.match(wizard, /window\.addEventListener\('mskba:contact:add', event => \{/);
    assert.match(wizard, /open\(type \|\| 'email', Boolean\(type\)\)/);
    assert.match(editor, /detail:\{type:key\}/);
    assert.match(wizard, /mskba:contact:updated/);
    assert.match(editor, /mskba:contact:updated',refreshChannels/);
});

test('disabled external channels have context actions rather than explanation paragraphs', () => {
    assert.equal((editor.match(/channelAction\(ch\.key\)==='add_contact'/g)||[]).length,3);
    assert.doesNotMatch(editor, /channelReason\(ch\.key\)/);
    assert.doesNotMatch(editor, /Добавить Телефон/);
});

test('checkbox text aligns vertically and inline plus replaces add-contact text links', () => {
    const css = readFileSync(new URL('../../resources/themes/mskba_app/css/play-interest.css', import.meta.url), 'utf8');
    assert.equal((editor.match(/class="app-play-dialog__channel-add"/g) || []).length, 3);
    assert.equal((editor.match(/:aria-label="'Добавить '\+ch.label"/g) || []).length, 3);
    assert.equal((editor.match(/<span aria-hidden="true">\+<\/span>/g) || []).length, 3);
    assert.equal((editor.match(/@click="addContact\(ch.key\)"/g) || []).length, 3);
    assert.doesNotMatch(editor, />Добавить \{\{ch.label\}\}<\/button>/);
    assert.match(css, /\.app-play-dialog__channel\{display:flex;align-items:center;/);
    assert.match(css, /\.app-play-dialog__settings \.app-play-dialog__channel \.check-label input\{\s*margin:0;align-self:center/);
    assert.match(css, /\.app-play-dialog__channel-add:focus-visible\{outline:/);
});

test('contact plus skips method picker while normal entry retains it', () => {
    assert.match(wizard, /function open\(preselected = 'email', skipMethod = false\)/);
    assert.match(wizard, /methodLocked = Boolean\(skipMethod && radio && !radio.disabled\)/);
    assert.match(wizard, /if \(methodLocked\) advanceFromMethod\(\)/);
    assert.match(wizard, /else showStep\('method'\)/);
    assert.match(wizard, /function advanceFromMethod\(\)/);
    assert.match(wizard, /configureValueField\(\);\s*showStep\('value'\)/);
    assert.match(wizard, /emulator.choose\(kind\);\s*showStep\('emulation'\)/);
    assert.match(wizard, /root.querySelector\('\[data-contact-add-open\]'\)\?\.addEventListener\('click', \(\) => open\(\)\)/);
    assert.match(wizard, /if \(methodLocked\) dialog.close\(\)/);
    assert.match(wizard, /back.textContent = methodLocked \? 'Отмена' : 'Назад'/);
});
