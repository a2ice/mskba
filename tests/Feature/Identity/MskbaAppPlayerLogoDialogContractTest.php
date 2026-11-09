<?php

namespace Tests\Feature\Identity;

use PHPUnit\Framework\TestCase;

final class MskbaAppPlayerLogoDialogContractTest extends TestCase
{
    public function test_avatar_generator_has_no_standalone_logo_toggle_or_picker(): void
    {
        $root = __DIR__.'/../../../resources/themes/mskba_app/';
        $fields = file_get_contents($root.'views/pages/account/partials/player-generation-fields.blade.php');
        $workspace = file_get_contents($root.'views/pages/account/partials/player-profile-workspace.blade.php');
        $options = file_get_contents($root.'js/player-generation-options.js');
        $submit = file_get_contents($root.'js/player-profile-workspace.js');

        self::assertStringContainsString('data-player-uniform-source', $fields);
        self::assertStringContainsString('data-player-generation-team', $fields);
        self::assertStringContainsString('data-player-generation-uniform-design', $fields);
        self::assertStringNotContainsString('data-player-logo-enabled', $fields);
        self::assertStringNotContainsString('С логотипом', $fields);
        self::assertStringNotContainsString('data-player-logo-picker-open', $fields);
        self::assertStringNotContainsString('player-generation-dialogs', $workspace);
        self::assertStringNotContainsString('logoCheckbox', $options);
        self::assertStringNotContainsString('logoDialog', $options);
        self::assertStringContainsString('setupPlayerUniformSelection(workspace)', $options);
        self::assertStringNotContainsString('generation_logo_mode', $submit);
        self::assertStringNotContainsString('generation_custom_logo_id', $submit);
    }

    public function test_logo_options_and_upload_live_in_uniform_workshop(): void
    {
        $root = __DIR__.'/../../../resources/themes/mskba_app/';
        $workshop = file_get_contents($root.'js/uniforms/UniformWorkshop.vue');

        self::assertStringContainsString('<legend>Логотип</legend>', $workshop);
        foreach (['none', 'team', 'custom'] as $source) {
            self::assertStringContainsString('name="logo-source" value="'.$source.'"', $workshop);
        }
        self::assertStringContainsString("payload.append('logo_source', form.logo_source)", $workshop);
        self::assertStringContainsString("payload.append('logo_team_id', form.logo_team_id)", $workshop);
        self::assertStringContainsString("payload.append('logo', logoFile.value)", $workshop);
        // Artwork placement is controlled by three independent checkboxes,
        // not by the old front/rear dropdowns.
        self::assertStringContainsString('mskba-uniform-editor__logo-checkboxes', $workshop);
        foreach (['front', 'back', 'shorts'] as $place) {
            self::assertStringContainsString('v-model="form.logo_'.$place.'_enabled"', $workshop);
            self::assertStringContainsString("payload.append('logo_' + place + '_enabled'", $workshop);
        }
        self::assertStringNotContainsString('mskba-uniform-editor__logo-positions', $workshop);
    }
}
