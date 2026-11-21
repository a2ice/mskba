import test from 'node:test';
import assert from 'node:assert/strict';
import { contactWizardNavigation } from '../../resources/themes/mskba_app/js/contact-wizard-navigation.js';

test('normal Contacts entry retains method selection and a working previous step', () => {
    assert.deepEqual(contactWizardNavigation('method', false), {
        footer:true, back:false, next:true, backTarget:null, backLabel:'Назад'
    });
    assert.deepEqual(contactWizardNavigation('value', false), {
        footer:true, back:true, next:true, backTarget:'method', backLabel:'Назад'
    });
});

test('subscription plus entry skips method and Cancel closes instead of reopening it', () => {
    assert.deepEqual(contactWizardNavigation('value', true), {
        footer:true, back:true, next:true, backTarget:'close', backLabel:'Отмена'
    });
    for (const step of ['telegram','vk','emulation']) {
        const navigation = contactWizardNavigation(step, true);
        assert.equal(navigation.backTarget,'close');
        assert.equal(navigation.backLabel,'Отмена');
        assert.equal(navigation.footer,true);
        assert.equal(navigation.next,false);
    }
});

test('email verification always offers Back to value for both entry contexts', () => {
    for (const locked of [true, false]) {
        assert.deepEqual(contactWizardNavigation('verification', locked), {
            footer:true, back:true, next:false, backTarget:'value', backLabel:'Назад'
        });
    }
});

test('normal non-email steps navigate back to chosen method and completion hides footer', () => {
    for (const step of ['telegram','vk','emulation']) {
        assert.equal(contactWizardNavigation(step, false).backTarget, 'method');
    }
    assert.deepEqual(contactWizardNavigation('done', true), {
        footer:false, back:false, next:false, backTarget:null, backLabel:'Назад'
    });
});

import { createContactCodeAutoSubmit } from '../../resources/themes/mskba_app/js/contact-code-auto-submit.js';

test('auto submit once for a contact, including paste; later attempts are manual', () => {
    const values = new Map();
    const storage = {getItem: key => values.get(key), setItem: (key,value) => values.set(key,value)};
    const flow = createContactCodeAutoSubmit(storage);
    assert.equal(flow.canSubmit(12, '12345'), false);
    assert.equal(flow.canSubmit(12, '12345x'), false);
    assert.equal(flow.canSubmit(null, '123456'), false);
    assert.equal(flow.canSubmit(12, '123456'), true);
    flow.markAttempt(12);
    assert.equal(flow.canSubmit(12, '654321'), false);
    assert.equal(createContactCodeAutoSubmit(storage).canSubmit(12, '654321'), false);
    assert.equal(flow.canSubmit(13, '123456'), true);
});
