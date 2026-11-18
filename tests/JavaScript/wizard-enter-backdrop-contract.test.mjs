import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const file = name => readFileSync(new URL('../../resources/themes/mskba_app/' + name, import.meta.url), 'utf8');

test('wizard Enter validates the active field before navigation', () => {
    const shell = file('js/components/WizardShell.vue');
    assert.match(shell, /@keydown\.enter="handleInputEnter"/);
    assert.match(shell, /validateEnteredField\(input\.name\)/);
    assert.match(shell, /const next = fields\[currentIndex \+ 1\]/);
    assert.match(shell, /else void forward\(true\)/);
    assert.match(shell, /await go\(visibleSteps\.value\[index\.value \+ 1\]\.id, focusFirstField\)/);
    assert.match(shell, /if \(await validateAllForSubmit\(\)\) emit\('submit'\)/);
    assert.match(shell, /delete issues\.value\[field\]/);
    assert.match(shell, /const names = new Set\(\)/);
});

test('registration retains wizard input/step while dismissed and never persists passwords', () => {
    const auth = file('js/components/AuthDialog.vue');
    assert.match(auth, /<dialog v-show="opened" ref="panel"/);
    assert.match(auth, /<RegistrationWizard v-show="mode === 'register'"/);
    assert.doesNotMatch(auth, /showDiscardConfirmation|discardDialog|sessionStorage|localStorage/);
    assert.doesNotMatch(auth, /mode\.value === 'register'\) return;/);
    const registration = file('js/components/RegistrationWizard.vue');
    assert.match(registration, /:aria-invalid="!!errors\[field\]"/);
    assert.match(registration, /@input="changed\(field\)"/);
    assert.match(registration, /:aria-invalid="!!errors\.privacy_consent"/);
});
