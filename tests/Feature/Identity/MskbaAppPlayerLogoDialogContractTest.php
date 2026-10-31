<?php

namespace Tests\Feature\Identity;

use PHPUnit\Framework\TestCase;

final class MskbaAppPlayerLogoDialogContractTest extends TestCase
{
    public function test_single_native_checkbox_replaces_logo_radios_and_uses_fixed_thumbnail(): void
    {
        $root = __DIR__.'/../../../resources/themes/mskba_app/';
        $fields = file_get_contents($root.'views/pages/account/partials/player-generation-fields.blade.php');
        $css = file_get_contents($root.'css/player-generation-options.css');
        $js = file_get_contents($root.'js/player-generation-options.js');
        $design = file_get_contents($root.'css/design-system.css');

        self::assertStringContainsString('class="check-label app-player-workspace__logo-toggle"', $fields);
        self::assertStringContainsString('type="checkbox" data-player-logo-enabled', $fields);
        self::assertStringContainsString('>С логотипом</span>', $fields);
        self::assertStringNotContainsString('name="generation_logo_enabled"', $fields);
        self::assertStringNotContainsString('name="generation_logo_source"', $fields);

        self::assertStringContainsString('data-player-logo-preview', $fields);
        self::assertStringContainsString('data-logo-placeholder=', $fields);
        self::assertStringContainsString('width="35" height="35"', $fields);
        self::assertStringContainsString('data-saved-logo-id=', $fields);
        self::assertStringContainsString('data-player-logo-picker-open', $fields);
        self::assertStringContainsString('aria-controls="app-player-logo-dialog"', $fields);
        self::assertStringContainsString('flex:0 0 35px;width:35px;height:35px', $css);
        self::assertStringContainsString('width:100%;height:100%;max-width:100%;max-height:100%;object-fit:cover', $css);
        self::assertStringContainsString('.check-label input{width:20px;height:20px;margin:0;accent-color:var(--accent)', $design);
        self::assertStringContainsString("withLogo ? 'custom' : 'none'", $js);
        self::assertStringContainsString('logoCheckbox?.addEventListener(\'change\', refreshMode)', $js);
        self::assertStringNotContainsString('data-player-logo-source', $js);
    }

    public function test_logo_dialog_reuses_unified_modal_and_accessible_close_icon(): void
    {
        $root = __DIR__.'/../../../resources/themes/mskba_app/';
        $dialog = file_get_contents($root.'views/pages/account/partials/player-generation-dialogs.blade.php');
        $js = file_get_contents($root.'js/player-generation-options.js');

        self::assertStringContainsString('class="mskba-modal app-player-workspace__logo-dialog"', $dialog);
        self::assertStringContainsString('mskba-modal__header app-profile__request-header', $dialog);
        self::assertStringContainsString('mskba-modal__body mskba-scroll app-profile__request-body', $dialog);
        self::assertStringContainsString('aria-labelledby="app-player-logo-title"', $dialog);
        self::assertStringContainsString('data-player-logo-close', $dialog);
        self::assertStringContainsString('aria-label="Закрыть">×</button>', $dialog);
        self::assertStringNotContainsString('data-player-logo-close>Закрыть', $dialog);
        self::assertStringContainsString('logoDialog.showModal()', $js);
        self::assertStringContainsString('logoDialog.close()', $js);
        self::assertStringContainsString("logoDialog?.addEventListener('close'", $js);
    }
}
