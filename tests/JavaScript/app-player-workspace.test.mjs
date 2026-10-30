import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const path = 'resources/themes/mskba_app/';
const load = name => readFileSync(new URL('../../' + path + name, import.meta.url), 'utf8');
const page = load('views/pages/account/partials/player-profile-workspace.blade.php');
const generation = load('views/pages/account/partials/player-generation-fields.blade.php');
const generationOptions = load('js/player-generation-options.js');
const generationStyles = load('css/player-generation-options.css');
const controller = readFileSync(new URL('../../app/Modules/Identity/Presentation/Http/Controllers/UpdatePlayerProfileController.php',import.meta.url),'utf8');
const client = load('js/player-profile-workspace.js');

test('physical and game tabs use separate server-validated form payloads',()=>{
    assert.match(page,/name="section" value="physical"/);
    assert.match(page,/name="section" value="game"/);
    assert.doesNotMatch(page,/name="character\[adiposity\]"/);
    assert.match(page,/name="character\[chest_volume\]"/);
    assert.match(page,/name="self_assessment\[/);
    assert.match(controller,/profileDataForSection\(\)/);
});

test('avatar generation runs directly with sequential status and clear failures',()=>{
    assert.match(client,/mutation:'generation_quote'/);
    assert.doesNotMatch(client,/window.confirm\(/);
    assert.match(client,/Проверяем стоимость и баланс/);
    assert.match(client,/Генерация аватара/);
    assert.match(client,/Готово! Аватар создан/);
    assert.match(client,/Ошибка генерации/);
    assert.match(client,/payload.set\('mutation','generate_2d'\)/);
    assert.match(client,/mutation:'generation_primary'/);
    assert.match(client,/target.origin !== location.origin/);
    assert.match(client,/response.status/);
    assert.match(client,/workspace.dispatchEvent\(new CustomEvent\('mskba:avatar-generated'\)\)/);
});

test('face references persist with private previews and deletion, verified only after generation',()=>{
    assert.match(generation,/data-face-photo/);
    assert.match(generation,/data-face-placeholder/);
    assert.match(generation,/data-face-delete/);
    assert.match(generationOptions,/face_reference_delete/);
    assert.match(generationOptions,/face_reference'/);
    assert.match(generationOptions,/generation_preferences/);
    assert.match(generationOptions,/face_references/);
    assert.match(generationOptions,/mskba:avatar-generated/);
    assert.match(generationOptions,/reference.verified === true/);
    assert.doesNotMatch(generationStyles,/face-upload.has-file .app-player-workspace__face-tick/);
    assert.match(generationStyles,/face-upload.is-verified .app-player-workspace__face-tick/);
    assert.match(generationStyles,/object-fit:cover/);
});

test('avatar starts with scaled default character and never runs generation on input alone',()=>{
    assert.match(page,/data-player-stage/);
    assert.match(page,/body-'.\$gender.'.webp/);
    assert.match(client,/function updateFigure\(\)/);
    assert.match(client,/--figure-height/);
    assert.match(client,/--figure-width/);
    assert.match(client,/generate\?\.addEventListener\('click'/);
    assert.match(load('css/player-profile-workspace.css'),/app-player-workspace__stage/);
});

test('player role uses guided native selects, sticky tabs and live height/weight sliders',()=>{
    assert.match(page,/type="range" name="height_cm" min="150" max="220"/);
    assert.match(page,/type="range" name="weight_kg" min="40" max="140"/);
    assert.match(page,/data-physical-height-value/);
    assert.match(page,/data-physical-weight-value/);
    assert.doesNotMatch(page,/Стройность/);
    assert.doesNotMatch(page,/Выраженность жировой ткани|Внешность персонажа/);
    assert.doesNotMatch(page,/data-player-preview-mode|До генерации показан базовый персонаж/);
    assert.match(generation,/Форма и кроссовки/);
    assert.match(generation,/Кроссовки/);
    assert.match(generation,/data-player-generation-team/);
    assert.match(generation,/data-player-face-slot/);
    assert.match(page,/account.partials.native-select-chevron/);
    assert.match(client,/addEventListener\('input', onPhysicalInput\)/);
    const css = load('css/player-profile-workspace.css');
    assert.match(css,/app-player-workspace__tabs\{position:sticky/);
    assert.match(css,/--app-sticky-offset/);
});
