<?php

namespace Tests\Feature\Identity;

use PHPUnit\Framework\TestCase;

final class MskbaAppPlayerUniformInlineEditContractTest extends TestCase
{
    public function test_selected_owned_uniform_opens_existing_workshop_with_save_in_place(): void
    {
        $root = __DIR__.'/../../../resources/themes/mskba_app/';
        $fields = file_get_contents($root.'views/pages/account/partials/player-generation-fields.blade.php');
        $selection = file_get_contents($root.'js/player-generation-uniform-selection.js');
        $modal = file_get_contents($root.'js/uniforms/UniformModal.vue');
        $workshop = file_get_contents($root.'js/uniforms/UniformWorkshop.vue');
        $styles = file_get_contents($root.'css/player-profile-workspace.css');

        self::assertStringContainsString('data-player-generation-uniform-design', $fields);
        self::assertStringContainsString('data-player-uniform-view', $fields);
        self::assertStringContainsString('>Посмотреть</button>', $fields);
        self::assertStringContainsString('aria-controls="mskba-uniform-dialog"', $fields);
        self::assertStringContainsString('id="mskba-uniform-dialog"', $modal);
        self::assertStringContainsString('data-player-uniform-view', $selection);
        self::assertStringContainsString("source.value !== 'mine'", $selection);
        self::assertStringContainsString('detail: { context: \'player-avatar\', projectId }', $selection);
        self::assertStringContainsString('if (view) view.disabled = !mineVisible || !design.value;', $selection);
        self::assertStringContainsString("design.addEventListener('change'", $selection);
        self::assertStringContainsString("event.detail?.context !== 'player-avatar'", $selection);
        self::assertStringContainsString('selectedOption.textContent = String(saved.name', $selection);
        self::assertStringContainsString(":initial-id=\"state.projectId\"", $modal);
        self::assertStringContainsString("if (!isTeamContext.value && editId.value) payload.append('_method', 'PUT')", $workshop);
        self::assertStringContainsString("props.context === 'player-avatar'", $workshop);
        self::assertStringContainsString("new CustomEvent('mskba:uniform:saved'", $workshop);
        self::assertStringContainsString('.app-player-workspace__uniform-design-controls', $styles);
    }

    public function test_height_scale_lines_render_below_avatar_silhouette(): void
    {
        $css = file_get_contents(__DIR__.'/../../../resources/themes/mskba_app/css/player-profile-workspace.css');
        self::assertStringContainsString('pointer-events:none;z-index:1;font-size:11px', $css);
        self::assertStringContainsString('.app-player-workspace__figure{z-index:2;pointer-events:none}', $css);
        self::assertStringContainsString('.app-player-workspace__scale-tick::after', $css);
    }
}
