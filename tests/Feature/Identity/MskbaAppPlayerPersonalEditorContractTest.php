<?php

namespace Tests\Feature\Identity;

use PHPUnit\Framework\TestCase;

final class MskbaAppPlayerPersonalEditorContractTest extends TestCase
{
    public function test_player_personal_editor_reuses_canonical_profile_fields_and_moderation(): void
    {
        $root = __DIR__.'/../../../resources/themes/mskba_app/';
        $workspace = file_get_contents($root.'views/pages/account/partials/player-profile-workspace.blade.php');
        $profilePage = file_get_contents($root.'views/pages/account/profile.blade.php');
        $editor = file_get_contents($root.'views/pages/account/partials/profile-personal-editor.blade.php');
        $actions = file_get_contents($root.'views/pages/account/partials/profile-personal-actions.blade.php');
        $requestDialog = file_get_contents($root.'views/pages/account/partials/profile-change-request-dialog.blade.php');
        $profileJs = file_get_contents($root.'js/account-profile.js');
        $profileSave = file_get_contents($root.'js/profile-inline-save.js');
        $controller = file_get_contents(__DIR__.'/../../../app/Modules/Identity/Presentation/Http/Controllers/AccountController.php');

        self::assertStringNotContainsString('Пол из профиля', $workspace);
        self::assertStringContainsString('data-player-gender-open data-player-gender-value', $workspace);
        self::assertStringContainsString('savedProfileGender', $workspace);
        self::assertStringContainsString("'не установлен'", $workspace);
        self::assertStringContainsString('data-player-gender-dialog', $workspace);
        self::assertStringContainsString('data-player-personal-dialog', $workspace);
        self::assertStringContainsString("partials.profile-personal-editor", $workspace);
        self::assertStringContainsString("partials.profile-personal-editor", $profilePage);
        self::assertStringContainsString("partials.profile-personal-actions", $workspace);
        self::assertStringNotContainsString('data-player-personal-close>Закрыть', $workspace);

        self::assertStringContainsString("route('account.profile.update')", $editor);
        self::assertStringContainsString('data-profile-personal-form', $editor);
        self::assertStringContainsString('profilePersonalInDialog', $editor);
        self::assertStringContainsString('data-inline-profile-save-all', $actions);
        self::assertStringContainsString('Применить</button>', $actions);
        self::assertStringContainsString('form="app-player-personal-form"', $actions);
        self::assertStringContainsString('data-profile-change-request-open', $actions);
        self::assertStringContainsString("form.closest('dialog')", $profileSave);
        self::assertStringContainsString('data-player-gender-value', $profileJs);
        self::assertStringContainsString("route('account.profile-change-requests.store')", $requestDialog);
        self::assertStringContainsString('data-profile-change-request-dialog', $requestDialog);

        self::assertStringContainsString("'profileConfirmed'", $controller);
        self::assertStringContainsString("'activeProfileChangeRequest'", $controller);
        self::assertStringContainsString("'latestProfileChangeRequest'", $controller);
    }
}
