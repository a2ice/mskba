<?php

namespace Tests\Feature\Identity;

use PHPUnit\Framework\TestCase;

final class MskbaAppPlayerEquipmentDialogContractTest extends TestCase
{
    public function test_grouped_equipment_renders_one_checkbox_per_type_and_legacy_side_fields(): void
    {
        $root = __DIR__.'/../../../resources/themes/mskba_app/';
        $blade = file_get_contents($root.'views/pages/account/partials/player-profile-workspace.blade.php')
            .file_get_contents($root.'views/pages/account/partials/player-generation-fields.blade.php');
        $js = file_get_contents($root.'js/player-profile-workspace.js');

        self::assertStringContainsString("'elbow' => ['name' => 'Налокотник'", $blade);
        self::assertStringContainsString("'wristband' => ['name' => 'Напульсник'", $blade);
        self::assertStringContainsString("'knee' => ['name' => 'Наколенник'", $blade);
        self::assertStringContainsString('data-player-equipment-group', $blade);
        self::assertStringContainsString('data-player-equipment-toggle', $blade);
        self::assertStringContainsString('data-player-equipment-suffix', $blade);
        self::assertStringContainsString('data-player-equipment-values', $blade);
        self::assertStringContainsString('name="character[attributes][]"', $blade);
        self::assertStringContainsString('value="headband"', $blade);
        self::assertStringContainsString("Appearance::normalizeAttributes(", $blade);
        self::assertStringContainsString("left && right ? ' (л/п)'", $js);
        self::assertStringContainsString("left ? ' (л)'", $js);
        self::assertStringContainsString("right ? ' (п)'", $js);
    }

    public function test_ui_kit_checkboxes_and_equipment_modal_footer_follow_accepted_design(): void
    {
        $root = __DIR__.'/../../../resources/themes/mskba_app/';
        $blade = file_get_contents($root.'views/pages/account/partials/player-profile-workspace.blade.php')
            .file_get_contents($root.'views/pages/account/partials/player-generation-fields.blade.php');
        $css = file_get_contents($root.'css/player-profile-workspace.css');
        $designSystem = file_get_contents($root.'css/design-system.css');

        self::assertStringContainsString('.check-label input{width:20px;height:20px;margin:0;accent-color:var(--accent)', $designSystem);
        self::assertStringContainsString('class="check-label app-player-workspace__equipment-toggle"', $blade);
        self::assertStringContainsString('class="check-label"><input type="checkbox" data-player-equipment-left>', $blade);
        self::assertStringContainsString('class="check-label"><input type="checkbox" data-player-equipment-right>', $blade);
        self::assertStringContainsString('data-player-equipment-cancel aria-label="Закрыть"', $blade);
        self::assertStringNotContainsString('data-player-equipment-cancel>Отмена', $blade);
        self::assertStringContainsString('data-player-equipment-apply>Применить', $blade);

        self::assertStringContainsString('min-height:44px', $css);
        self::assertStringContainsString('.app-player-workspace__equipment-groups .app-player-workspace__equipment-toggle', $css);
        self::assertStringContainsString('align-self:center;min-height:44px', $css);
        self::assertStringContainsString('accent-color:var(--accent)', $css);
        self::assertStringNotContainsString('.app-player-workspace__equipment-side-options input{width:17px', $css);
    }

    public function test_side_dialog_commits_only_on_apply_and_cancel_keeps_existing_selections(): void
    {
        $root = __DIR__.'/../../../resources/themes/mskba_app/';
        $blade = file_get_contents($root.'views/pages/account/partials/player-profile-workspace.blade.php')
            .file_get_contents($root.'views/pages/account/partials/player-generation-fields.blade.php');
        $js = file_get_contents($root.'js/player-profile-workspace.js');
        $css = file_get_contents($root.'css/player-profile-workspace.css');

        self::assertStringContainsString('<dialog class="mskba-modal app-player-workspace__equipment-dialog"', $blade);
        self::assertStringContainsString('data-player-equipment-cancel', $blade);
        self::assertStringContainsString('data-player-equipment-apply', $blade);
        self::assertStringContainsString('data-player-equipment-left', $blade);
        self::assertStringContainsString('data-player-equipment-right', $blade);
        self::assertStringContainsString('aria-labelledby="app-player-equipment-dialog-title"', $blade);
        self::assertStringContainsString('equipmentDialog.showModal()', $js);
        self::assertStringContainsString('updateEquipmentGroup(activeEquipmentGroup, selected)', $js);
        self::assertStringContainsString("updateEquipmentGroup(group, [])", $js);
        self::assertStringContainsString('equipmentDialog.close()', $js);
        self::assertStringContainsString("input.name = 'character[attributes][]'", $js);
        self::assertStringContainsString('equipmentReturnFocus', $js);
        self::assertStringContainsString('.app-player-workspace__equipment-dialog', $css);
    }
}
