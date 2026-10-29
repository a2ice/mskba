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

    public function test_avatar_figure_reaches_220cm_without_width_clamp_and_ruler_is_thin(): void
    {
        $root = __DIR__.'/../../../resources/themes/mskba_app/';
        $blade = file_get_contents($root.'views/pages/account/partials/player-profile-workspace.blade.php');
        $css = file_get_contents($root.'css/player-profile-workspace.css');
        $js = file_get_contents($root.'js/player-profile-workspace.js');

        preg_match('/\\.app-player-workspace__figure img\\{([^}]+)\\}/', $css, $match);
        $this->assertNotEmpty($match);
        $imageRules = $match[1];
        $this->assertStringContainsString('flex:0 0 auto', $imageRules);
        $this->assertStringContainsString('height:var(--figure-height', $imageRules);
        $this->assertStringContainsString('max-width:none', $imageRules);
        $this->assertStringNotContainsString('max-width:95%', $imageRules);

        $this->assertStringContainsString('scaleHeight / 225 * 100', $js);
        $this->assertStringContainsString('max="220"', $blade);
        $this->assertStringContainsString("stage.style.setProperty('--figure-height', (scaleHeight / 225 * 100)", $js);
        $this->assertStringContainsString("heightMarker.style.bottom = (scaleHeight / 225 * 100)", $js);
        $this->assertStringContainsString('height:1px;background:var(--accent', $css);
    }

    public function test_ruler_can_be_dragged_on_mouse_or_touch_and_updates_native_height_slider(): void
    {
        $root = __DIR__.'/../../../resources/themes/mskba_app/';
        $css = file_get_contents($root.'css/player-profile-workspace.css');
        $js = file_get_contents($root.'js/player-profile-workspace.js');

        $this->assertStringContainsString("addEventListener('pointerdown'", $js);
        $this->assertStringContainsString("addEventListener('pointermove'", $js);
        $this->assertStringContainsString("'pointerup', 'pointercancel', 'lostpointercapture'", $js);
        $this->assertStringContainsString('setPointerCapture(event.pointerId)', $js);
        $this->assertStringContainsString('releasePointerCapture(event.pointerId)', $js);
        $this->assertStringContainsString('(rect.bottom - clientY) / rect.height * 225', $js);
        $this->assertStringContainsString('Math.max(Number(height.min), Math.min(Number(height.max)', $js);
        $this->assertStringContainsString("height.dispatchEvent(new Event('input'", $js);
        $this->assertStringContainsString('touch-action:none', $css);
        $this->assertStringContainsString('cursor:ns-resize', $css);
        $this->assertStringContainsString('.app-player-workspace__stage.is-height-dragging', $css);
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
