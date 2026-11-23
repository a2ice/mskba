import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const source = path => readFileSync(new URL('../../' + path, import.meta.url), 'utf8');

test('both themes install the venue description lightbox', () => {
    const app = source('resources/themes/mskba_app/js/app.js');
    const classic = source('resources/themes/mskba_dark/js/app.js');
    assert.match(app, /import '\.\/venue-description-lightbox\.js'/);
    assert.match(classic, /import '\.\.\/\.\.\/mskba_app\/js\/venue-description-lightbox\.js'/);
    assert.match(app, /venue-description-rich\.css/);
    assert.match(classic, /venue-description-rich\.css/);
});

test('lightbox requires image anchor, safe URL, and supports accessible controls', () => {
    const script = source('resources/themes/mskba_app/js/venue-description-lightbox.js');
    assert.match(script, /data-venue-lightbox/);
    assert.match(script, /anchor\.querySelector\('img'\)/);
    assert.match(script, /\['http:', 'https:'\]/);
    assert.match(script, /'ArrowLeft'/);
    assert.match(script, /'ArrowRight'/);
    assert.match(script, /\.showModal\(\)/);
    assert.match(script, /downOnBackdrop/);
    assert.match(script, /data-venue-occupancy-day/);
});

test('venue description lightbox centers its photo card independently of dialog placement', () => {
    const css = source('resources/themes/mskba_app/css/venue-description-rich.css');
    assert.match(css, /dialog\\.venue-description-lightbox\\[open\\]\\s*\\{display:grid;place-items:center\\}/);
    assert.match(css, /dialog\\.venue-description-lightbox\\s*\\{[\\s\\S]*?width:100vw;max-width:none/);
    assert.match(css, /dialog\\.venue-description-lightbox\\s*\\{[\\s\\S]*?height:100dvh;max-height:none/);
    assert.match(css, /\\.venue-description-lightbox__frame\\s*\\{[\\s\\S]*?width:min\\(1100px,calc\\(100vw - 24px\\)\\)/);
    assert.match(css, /\\.venue-description-lightbox__image\\s*\\{[\\s\\S]*?max-height:calc\\(92dvh - 88px\\)/);
});

test('backoffice editor embeds safe gallery and venue action markup', () => {
    const editor = source('app/Filament/Backoffice/Resources/Venues/VenueResource.php');
    assert.match(editor, /insertVenueDescriptionPhotos/);
    assert.match(editor, /insertVenueDescriptionAction/);
    assert.match(editor, /data-venue-lightbox="venue-photos"/);
    assert.match(editor, /data-venue-rental-open="1"/);
    assert.match(editor, /data-venue-play-open="1"/);
});
