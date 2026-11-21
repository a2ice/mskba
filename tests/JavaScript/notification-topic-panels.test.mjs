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
    assert.match(wizard, /open\(type \|\| 'email', Boolean\(type\), options\)/);
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
    assert.match(wizard, /function open\(preselected = 'email', skipMethod = false, options = \{\}\)/);
    assert.match(wizard, /methodLocked = Boolean\(skipMethod && radio && !radio.disabled\)/);
    assert.match(wizard, /if \(methodLocked\) advanceFromMethod\(\)/);
    assert.match(wizard, /else showStep\('method'\)/);
    assert.match(wizard, /function advanceFromMethod\(\)/);
    assert.match(wizard, /configureValueField\(\);\s*showStep\('value'\)/);
    assert.match(wizard, /emulator.choose\(kind\);\s*showStep\('emulation'\)/);
    assert.match(wizard, /root.querySelector\('\[data-contact-add-open\]'\)\?\.addEventListener\('click', \(\) => open\(\)\)/);
    assert.match(wizard, /contactWizardNavigation\(step, methodLocked\)\.backTarget/);
    assert.match(wizard, /back.textContent = navigation.backLabel/);
});

test('email verification has a real Back action to edit the existing value', () => {
    assert.match(wizard, /contactWizardNavigation\(target, methodLocked\)/);
    assert.match(wizard, /footer.hidden = !navigation.footer/);
    assert.match(wizard, /if \(target === 'close'\) dialog.close\(\)/);
    assert.match(wizard, /else if \(target\) \{[\s\S]*?showStep\(target\);/);
    assert.match(wizard, /verificationEmail=value.value.toLowerCase\(\)/);
    assert.match(wizard, /value.value.toLowerCase\(\) === verificationEmail/);
    assert.match(wizard, /showStep\('verification'\);\s*return;/);
});

test('verification actions and change choice use the shared contact dialog', () => {
    const footer = partial.match(/data-contact-add-footer[\s\S]*?<\/div>/)?.[0];
    assert.ok(footer);
    assert.match(footer, /data-contact-add-back[\s\S]*data-contact-add-verify/);
    assert.match(partial, /data-contact-add-resend[\s\S]*data-contact-add-change/);
    assert.match(partial, /data-contact-change-modal[\s\S]*data-contact-change-add[\s\S]*data-contact-change-replace/);
    assert.match(wizard, /replace_contact_id: verificationContactId/);
    assert.match(wizard, /if \(!replacing\) await sendEmailCode\(\)/);
    assert.match(wizard, /createContactResendCooldown/);
});

test('embedded contact completion shows explicit continuation and does not refresh missing list', () => {
    assert.match(wizard, /function completeContact\(message, verified\)/);
    assert.match(wizard, /contactWizardCompletion\(embedded, verified\) === 'continue'[\s\S]*?showStep\('done'\)/);
    assert.match(wizard, /document.querySelector\('\[data-account-contacts-list\]'\)/);
    assert.match(wizard, /requireVerified: event.detail\?\.requireVerified === true/);
    assert.match(wizard, /dialog.returnValue = ''/);
    assert.match(wizard, /continueButton.addEventListener\('click'[\s\S]*?dialog.close\('verified'\)/);
    assert.match(partial, /data-contact-add-continue hidden>Продолжить/);
});
