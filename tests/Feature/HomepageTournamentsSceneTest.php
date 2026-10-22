<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class HomepageTournamentsSceneTest extends TestCase
{
    use RefreshDatabase;

    public function test_feed_has_named_optional_media_slots_and_no_fabricated_tournaments(): void
    {
        $this->getJson('/api/v1/home/tournaments-scene')
            ->assertOk()
            ->assertJsonStructure(['assets' => ['background', 'rim', 'ball'], 'tournaments'])
            ->assertJsonPath('assets.background', null)
            ->assertJsonPath('assets.rim', null)
            ->assertJsonPath('assets.ball', null)
            ->assertJsonCount(0, 'tournaments');
    }

    public function test_component_uses_full_width_scene_with_constrained_content_and_separate_layers(): void
    {
        $html = file_get_contents(resource_path('page-builder/tournaments-scene.html'));
        $css = file_get_contents(resource_path('page-builder/tournaments-scene.css'));
        $js = file_get_contents(resource_path('page-builder/tournaments-scene.js'));

        $this->assertStringContainsString('<section class="mskba-tournaments"', $html);
        $this->assertStringContainsString('<div class="container mskba-tournaments__container">', $html);
        foreach (['background', 'rim', 'ball'] as $layer) {
            $this->assertStringContainsString('mskba-tournaments__'.$layer, $html);
            $this->assertStringContainsString('data.assets?.'.$layer, $js);
        }
        $this->assertStringContainsString('--tournaments-rim-x', $css);
        $this->assertStringContainsString('--tournaments-rim-scale', $css);
        $this->assertStringContainsString('@media (max-width: 767px)', $css);
        $this->assertStringContainsString("'/api/v1/home/tournaments-scene'", $js);
        $this->assertStringNotContainsString('32 команды', $html);
        $this->assertStringContainsString('href="/tournaments"', $html);
    }

    public function test_legacy_home_section_pattern_accepts_quoted_id_attribute(): void
    {
        $source = file_get_contents(app_path('Console/Commands/InstallHomepageTournamentsScene.php'));
        $this->assertStringContainsString('id="tournaments"(?=\\s|>)', $source);
        $this->assertStringNotContainsString('id="tournaments"\\b', $source);
    }

    public function test_publisher_refuses_to_modify_other_environments(): void
    {
        $this->artisan('mskba:tournaments-scene:install', ['--apply' => true])
            ->assertExitCode(1);
    }
}
