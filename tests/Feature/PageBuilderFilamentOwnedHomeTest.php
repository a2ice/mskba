<?php

namespace Tests\Feature;

use App\Modules\Content\Application\Services\PageBuilderMarkup;
use App\Modules\Content\Application\Services\PageBuilderPublisher;
use App\Modules\Content\Domain\Models\PageBuilderPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PageBuilderFilamentOwnedHomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_render_no_longer_injects_venues_markup_and_preserves_page_owned_html(): void
    {
        $this->withoutVite();
        $page = PageBuilderPage::query()->where('path', '/')->firstOrFail();
        $page->update(['draft_html' => '<section class="custom-home" id="hero">'
            .'<h1>Hero, controlled by Filament</h1></section>']);
        app(PageBuilderPublisher::class)->publish($page);

        $this->get('/')->assertOk()
            ->assertSee('Hero, controlled by Filament')
            ->assertDontSee('mskba-home-story__art')
            ->assertDontSee('data-mskba-home-story')
            ->assertDontSee('mskba-venues-scene');
    }

    public function test_ball_assets_are_not_part_of_vite_and_are_available_for_filament_copying(): void
    {
        $css = file_get_contents(resource_path('css/page-builder.css'));
        $js = file_get_contents(resource_path('js/page-builder.js'));

        $this->assertStringNotContainsString('page-builder-rolling-ball.css', $css);
        $this->assertStringNotContainsString('page-builder-venues-scene.css', $css);
        $this->assertStringNotContainsString('page-builder-rolling-ball.js', $js);
        $this->assertStringNotContainsString('page-builder-venues-motion.js', $js);

        $this->assertFileDoesNotExist(resource_path('css/page-builder-rolling-ball.css'));
        $this->assertFileDoesNotExist(resource_path('js/page-builder-rolling-ball.js'));
        $this->assertFileDoesNotExist(resource_path('css/page-builder-venues-scene.css'));
        $this->assertFileDoesNotExist(resource_path('js/page-builder-venues-motion.js'));

        $ballCss = file_get_contents(base_path('docs/page-builder/filament-home/ball.css'));
        $ballJs = file_get_contents(base_path('docs/page-builder/filament-home/ball.js'));
        $this->assertStringContainsString('.mskba-rolling-ball::before', $ballCss);
        $this->assertStringContainsString('rollingBallProfiles', $ballJs);
        $this->assertStringContainsString(".mskba-home-story", $ballJs,
            'Filament sanitizes arbitrary data-* attributes, so use class selectors');
        $this->assertStringNotContainsString("[data-mskba-home-story]", $ballJs);
    }

    public function test_flexible_html_editor_keeps_ball_markup_and_rejects_scripts(): void
    {
        $markup = app(PageBuilderMarkup::class);
        $html = $markup->html(
            '<div class="mskba-hero-scene">'
            .'<div class="mskba-rolling-ball"><img class="mskba-rolling-ball__sprite"'
            .' src="/images/ball-16.webp" alt=""></div></div>'
        );
        $this->assertStringContainsString('class="mskba-rolling-ball"', $html);
        $this->assertStringContainsString('src="/images/ball-16.webp"', $html);
        $this->assertStringNotContainsString('<script', $html);
    }
}
