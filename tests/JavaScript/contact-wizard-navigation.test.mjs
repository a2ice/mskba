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
