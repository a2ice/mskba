<?php

namespace Tests\Feature\Identity;

use PHPUnit\Framework\TestCase;

final class MskbaAppPlayerUniformSelectorContractTest extends TestCase
{
    public function test_four_source_options_and_conditional_native_selects_match_mskba_app_guideline(): void
    {
        $root = __DIR__.'/../../../resources/themes/mskba_app/';
        $html = file_get_contents($root.'views/pages/account/partials/player-generation-fields.blade.php');
        $css = file_get_contents($root.'css/player-generation-options.css');
        $state = file_get_contents($root.'js/player-generation-uniform-selection.js');

        self::assertStringContainsString('data-player-uniform-source', $html);
        foreach (['neutral', 'team', 'mine', '__create__'] as $source) {
            self::assertStringContainsString('value="'.$source.'"', $html);
        }
        self::assertStringContainsString('data-player-generation-team', $html);
        self::assertStringContainsString('data-player-generation-uniform-design', $html);
        self::assertStringContainsString('data-player-uniform-team-field', $html);
        self::assertStringContainsString('data-player-uniform-design-field', $html);
        self::assertStringContainsString('data-player-uniform-team-field][hidden]', $css);
        self::assertStringContainsString('data-player-uniform-design-field][hidden]', $css);
        self::assertStringContainsString("source.value = 'neutral'", $state);
        self::assertStringContainsString("source.value = 'mine'", $state);
        self::assertStringContainsString('design.disabled = !mineVisible', $state);
        self::assertStringContainsString('team.disabled = !teamVisible', $state);
        self::assertStringContainsString("fetch('/uniforms/projects'", $state);
        self::assertStringContainsString("event.detail?.context !== 'player-avatar'", $state);
    }

    public function test_workshop_saves_into_avatar_context_without_navigation_and_cancel_restores_neutral(): void
    {
        $root = __DIR__.'/../../../resources/themes/mskba_app/js/';
        $workshop = file_get_contents($root.'uniforms/UniformWorkshop.vue');
        $modal = file_get_contents($root.'uniforms/UniformModal.vue');
        $state = file_get_contents($root.'player-generation-uniform-selection.js');

        self::assertStringContainsString("props.context === 'player-avatar'", $workshop);
        self::assertStringContainsString("new CustomEvent('mskba:uniform:saved'", $workshop);
        self::assertStringContainsString("window.location.assign('/account/uniforms?saved=1')", $workshop);
        self::assertStringContainsString("new CustomEvent('mskba:uniform:closed'", $modal);
        self::assertStringContainsString("close(true)", $modal);
        self::assertStringContainsString("!event.detail.saved && creating", $state);
    }

    public function test_paid_generation_sends_only_selected_and_owned_uniform_source(): void
    {
        $root = __DIR__.'/../../../';
        $client = file_get_contents($root.'resources/themes/mskba_app/js/player-profile-workspace.js');
        $handler = file_get_contents($root.'app/Modules/Identity/Application/UseCases/GeneratePlayerCharacterTwoDimensionalHandler.php');

        self::assertStringContainsString("payload.set('generation_uniform_source', source)", $client);
        self::assertStringContainsString("payload.set('generation_uniform_design_id', design.value)", $client);
        self::assertStringContainsString("payload.set('generation_team_id', team.value)", $client);
        self::assertStringContainsString('->whereIn(\'user_id\', $user->identityIds())', $handler);
        self::assertStringContainsString("'uniform_design_unavailable'", $handler);
        self::assertStringContainsString("'uniform_team_required'", $handler);
        self::assertStringContainsString("'source' => \$hasTeamKit ? 'team' : 'neutral'", $handler);
    }
}
