<?php

namespace Tests\Feature\Identity;

use PHPUnit\Framework\TestCase;

final class MskbaAppPlayerAvatarGalleryContractTest extends TestCase
{
    public function test_avatar_gallery_uses_authorized_history_and_explicit_primary_action(): void
    {
        $root = __DIR__.'/../../../resources/themes/mskba_app/';
        $blade = file_get_contents($root.'views/pages/account/partials/player-profile-workspace.blade.php');
        $css = file_get_contents($root.'css/player-profile-workspace.css');
        $js = file_get_contents($root.'js/player-profile-workspace.js');

        self::assertStringContainsString('Аватаров:', $blade);
        self::assertStringContainsString('data-player-avatar-rail', $blade);
        self::assertStringContainsString('data-player-avatar-count', $blade);
        self::assertStringContainsString('data-player-avatar-empty', $blade);
        self::assertStringContainsString('data-player-avatar-make-primary', $blade);
        self::assertStringContainsString('data-player-avatar-feedback', $blade);

        self::assertStringContainsString("mutation:'generation_history'", $js);
        self::assertStringContainsString("mutation:'generation_primary'", $js);
        self::assertStringContainsString('button.addEventListener(\'click\'', $js);
        self::assertStringContainsString('setImage(item.image_url)', $js);
        self::assertStringContainsString('role="group"', $blade);
        self::assertStringContainsString('overflow-x:auto', $css);
        self::assertStringContainsString('calc((100% - 24px)/4)', $css);
    }

    public function test_acceptance_empty_gallery_page_title_and_hoverable_ruler(): void
    {
        $root = __DIR__.'/../../../resources/themes/mskba_app/';
        $rolePage = file_get_contents($root.'views/pages/account/participation-role.blade.php');
        $blade = file_get_contents($root.'views/pages/account/partials/player-profile-workspace.blade.php');
        $css = file_get_contents($root.'css/player-profile-workspace.css');
        $js = file_get_contents($root.'js/player-profile-workspace.js');

        self::assertStringContainsString("\$role->value === 'player' ? \$role->label()", $rolePage);
        self::assertStringContainsString('data-player-avatar-heading hidden', $blade);
        self::assertStringContainsString('data-player-avatar-rail hidden', $blade);
        self::assertStringContainsString('data-player-avatar-empty', $blade);
        self::assertStringContainsString('avatarHeading.hidden = !hasAvatars', $js);
        self::assertStringContainsString('avatarRail.hidden = !hasAvatars', $js);
        self::assertStringContainsString('avatarEmpty.hidden = hasAvatars', $js);
        self::assertStringContainsString('.app-player-workspace__avatar-heading[hidden]', $css);

        self::assertStringContainsString('.app-player-workspace__height-marker:hover span', $css);
        self::assertStringContainsString('.app-player-workspace__stage.is-height-focused', $css);
        self::assertStringContainsString('opacity:0;visibility:hidden', $css);
        self::assertStringContainsString('height-marker::before', $css);
        self::assertStringContainsString("stage?.classList.add('is-height-focused')", $js);
        self::assertStringContainsString("stage?.classList.remove('is-height-focused')", $js);
    }

    public function test_height_labels_marker_and_figure_use_a_single_225cm_reference(): void
    {
        $root = __DIR__.'/../../../resources/themes/mskba_app/';
        $blade = file_get_contents($root.'views/pages/account/partials/player-profile-workspace.blade.php');
        $css = file_get_contents($root.'css/player-profile-workspace.css');
        $js = file_get_contents($root.'js/player-profile-workspace.js');

        self::assertStringContainsString('[0,50,100,150,200,225]', $blade);
        self::assertStringContainsString('$height / 225 * 100', $blade);
        self::assertStringContainsString('$heightValue / 225 * 100', $blade);
        self::assertStringContainsString('data-player-height-marker', $blade);
        self::assertStringContainsString('data-player-height-marker-label', $blade);
        self::assertStringContainsString('scaleHeight / 225 * 100', $js);
        self::assertStringContainsString('heightMarker.style.bottom', $js);
        self::assertStringContainsString('.app-player-workspace__scale-tick', $css);
        self::assertStringContainsString('.app-player-workspace__height-marker span', $css);
        self::assertStringNotContainsString('[250,200,150,100,50,0]', $blade);
    }
}
