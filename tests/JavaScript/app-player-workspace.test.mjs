import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const path = 'resources/themes/mskba_app/';
const load = name => readFileSync(new URL('../../' + path + name, import.meta.url), 'utf8');
const page = load('views/pages/account/partials/player-profile-workspace.blade.php');
const controller = readFileSync(new URL('../../app/Modules/Identity/Presentation/Http/Controllers/UpdatePlayerProfileController.php',import.meta.url),'utf8');
const client = load('js/player-profile-workspace.js');

test('physical and game tabs use separate server-validated form payloads',()=>{
    assert.match(page,/name="section" value="physical"/);
    assert.match(page,/name="section" value="game"/);
    assert.match(page,/name="character\[adiposity\]"/);
    assert.match(page,/name="character\[chest_volume\]"/);
    assert.match(page,/name="self_assessment\[/);
    assert.match(controller,/profileDataForSection\(\)/);
});

test('avatar generation requires price confirmation and uses existing paid backend',()=>{
    assert.match(client,/mutation:'generation_quote'/);
    assert.match(client,/window.confirm\(/);
    assert.match(client,/payload.set\('mutation','generate_2d'\)/);
    assert.match(client,/generation_face_references\[/);
    assert.match(client,/mutation:'generation_primary'/);
    assert.match(client,/target.origin !== location.origin/);
});

test('avatar starts with scaled default character and never runs generation on input alone',()=>{
    assert.match(page,/data-player-stage/);
    assert.match(page,/body-'.\$gender.'.png/);
    assert.match(client,/function updateFigure\(\)/);
    assert.match(client,/--figure-height/);
    assert.match(client,/--figure-width/);
    assert.match(client,/generate\?\.addEventListener\('click'/);
    assert.match(load('css/player-profile-workspace.css'),/app-player-workspace__stage/);
});
