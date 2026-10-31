import test from 'node:test';
import assert from 'node:assert/strict';
import { resolvePlayerLogoPreview } from '../../resources/themes/mskba_app/js/player-generation-logo-state.js';

const origin = 'https://dev.mskba.ru';
const placeholder = '/images/team-placeholder.webp';
const logos = [
    { id: 41, name: 'Логотип команды', url: '/account/player-character/logos/41' },
    { id: 42, name: 'Другой логотип', url: 'https://dev.mskba.ru/account/player-character/logos/42' },
];

test('saved personal logo uses same-origin URL with informative name', () => {
    const preview = resolvePlayerLogoPreview(logos, 41, origin, placeholder);
    assert.deepEqual(preview, {
        src: origin + '/account/player-character/logos/41',
        alt: 'Логотип команды',
        label: 'Логотип команды',
    });
    assert.equal(resolvePlayerLogoPreview(logos, '42', origin, placeholder).label, 'Другой логотип');
});

test('missing or deleted selection shows normal placeholder', () => {
    for (const id of [null, 0, 43, undefined]) {
        assert.deepEqual(resolvePlayerLogoPreview(logos, id, origin, placeholder), {
            src: placeholder,
            alt: 'Логотип не выбран',
            label: 'Логотип не выбран',
        });
    }
});

test('private previews never load cross-origin or executable URLs', () => {
    const invalid = [
        { id: 1, name: 'External', url: 'https://other.test/steal' },
        { id: 2, name: 'Script', url: 'javascript:alert(1)' },
        { id: 3, name: 'Invalid', url: 'http://dev.mskba.ru/insecure' },
        { id: 4, name: 'Invalid', url: 'http://[' },
    ];
    for (const logo of invalid) {
        assert.equal(resolvePlayerLogoPreview(invalid, logo.id, origin, placeholder).src, placeholder);
    }
});
