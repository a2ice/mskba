<?php

namespace Tests\Feature\Identity;

use PHPUnit\Framework\TestCase;

final class MskbaAppPlayerAvatarSelectionContractTest extends TestCase
{
    public function test_completed_generation_is_selected_for_preview_not_the_previous_avatar(): void
    {
        $js = file_get_contents(__DIR__.'/../../../resources/themes/mskba_app/js/player-profile-workspace.js');

        self::assertStringContainsString('selectedAvatarId = id;', $js);
        self::assertStringContainsString('await showExisting({generation_id:id, image_url:data.image_url});', $js);
        self::assertStringContainsString('if (preferredId && availableIds.has(preferredId))', $js);
        self::assertStringContainsString('selectedAvatarId = preferredId;', $js);
        self::assertStringContainsString('setImage(selected.image_url);', $js);
        self::assertStringContainsString('requestAnimationFrame(scrollToGeneratedAvatar);', $js);
        self::assertStringContainsString('requestAnimationFrame(scrollToGenerationError);', $js);
        self::assertStringContainsString('scrollToPlayerTarget(feedback)', $js);
        self::assertStringContainsString("data.status === 'failed'", $js);
        self::assertStringContainsString('const preferredUrl = ownImageUrl(preferredAvatar?.image_url);', $js);
    }

    public function test_make_primary_is_a_text_link_styled_like_primary_label(): void
    {
        $root = __DIR__.'/../../../resources/themes/mskba_app/';
        $markup = file_get_contents($root.'views/pages/account/partials/player-profile-workspace.blade.php');
        $styles = file_get_contents($root.'css/player-profile-workspace.css');
        $js = file_get_contents($root.'js/player-profile-workspace.js');

        self::assertStringContainsString('<a href="#" class="app-player-workspace__avatar-primary-link" data-player-avatar-make-primary hidden>', $markup);
        self::assertStringNotContainsString('<button type="button" class="app-player-workspace__secondary" data-player-avatar-make-primary', $markup);
        self::assertStringContainsString('.app-player-workspace__avatar-primary-label,.app-player-workspace__avatar-primary-link', $styles);
        self::assertStringContainsString('text-decoration:none', $styles);
        self::assertStringContainsString('event.preventDefault();', $js);
        self::assertStringContainsString("makePrimary.setAttribute('aria-disabled', String(selectingPrimary))", $js);
    }
}
