<?php

namespace Tests\Feature\Identity;

use PHPUnit\Framework\TestCase;

final class MskbaAppPlayerPersonalEditorContractTest extends TestCase
{
    public function test_player_personal_editor_reuses_canonical_profile_fields_and_moderation(): void
    {
        $workspace = file_get_contents(__DIR__.'/../../../resources/themes/mskba_app/views/pages/account/partials/player-profile-workspace.blade.php');
        $profilePage = file_get_contents(__DIR__.'/../../../resources/themes/mskba_app/views/pages/account/profile.blade.php');
        $editor = file_get_contents(__DIR__.'/../../../resources/themes/mskba_app/views/pages/account/partials/profile-personal-editor.blade.php');
        $requestDialog = file_get_contents(__DIR__.'/../../../resources/themes/mskba_app/views/pages/account/partials/profile-change-request-dialog.blade.php');
        $controller = file_get_contents(__DIR__.'/../../../app/Modules/Identity/Presentation/Http/Controllers/AccountController.php');

        self::assertStringNotContainsString('Пол из профиля', $workspace);
        self::assertStringContainsString('data-player-gender-open', $workspace);
        self::assertStringContainsString('data-player-gender-dialog', $workspace);
        self::assertStringContainsString('data-player-personal-dialog', $workspace);
        self::assertStringContainsString("partials.profile-personal-editor", $workspace);
        self::assertStringContainsString("partials.profile-personal-editor", $profilePage);

        self::assertStringContainsString("route('account.profile.update')", $editor);
        self::assertStringContainsString('data-inline-profile-save-all', $editor);
        self::assertStringContainsString('>Применить</button>', $editor);
        self::assertStringContainsString('data-profile-change-request-open', $editor);
        self::assertStringContainsString("route('account.profile-change-requests.store')", $requestDialog);
        self::assertStringContainsString('data-profile-change-request-dialog', $requestDialog);

        self::assertStringContainsString("'profileConfirmed' => $canonical->isConfirmed()", $controller);
        self::assertStringContainsString("'activeProfileChangeRequest'", $controller);
        self::assertStringContainsString("'latestProfileChangeRequest'", $controller);
    }
}
