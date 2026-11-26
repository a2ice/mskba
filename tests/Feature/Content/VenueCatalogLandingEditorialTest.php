<?php

namespace Tests\Feature\Content;

use App\Modules\Content\Domain\Models\VenueCatalogLanding;
use Tests\TestCase;

final class VenueCatalogLandingEditorialTest extends TestCase
{
    public function test_editorial_html_is_sanitized_and_rendered_in_both_positions(): void
    {
        config(['features.venue_catalog_landings.enabled' => true]);
        $landing = new VenueCatalogLanding([
            'is_active' => true,
            'intro_text' => '<p onclick="alert(1)">Intro <strong>safe</strong></p>',
            'body_text' => '<h2>FAQ</h2><p><a href="/venues">All venues</a></p><script>alert(1)</script>',
        ]);

        $intro = $this->renderBlock($landing, 'intro');
        $this->assertStringContainsString('data-venue-landing-editorial="intro"', $intro);
        $this->assertStringContainsString('<strong>safe</strong>', $intro);
        $this->assertStringNotContainsString('onclick', $intro);
        $this->assertStringNotContainsString('FAQ', $intro);

        $body = $this->renderBlock($landing, 'body');
        $this->assertStringContainsString('<h2>FAQ</h2>', $body);
        $this->assertStringContainsString('href="/venues"', $body);
        $this->assertStringNotContainsString('<script', $body);
        $this->assertStringNotContainsString('Intro', $body);
    }

    public function test_no_editorial_html_for_drafts_disabled_gate_or_later_pages(): void
    {
        $landing = new VenueCatalogLanding(['is_active' => true, 'intro_text' => '<p>Private draft</p>']);
        config(['features.venue_catalog_landings.enabled' => false]);
        $this->assertSame('', trim($this->renderBlock($landing, 'intro')));
        config(['features.venue_catalog_landings.enabled' => true]);
        $this->assertSame('', trim($this->renderBlock($landing, 'intro', 2)));
        $landing->is_active = false;
        $this->assertSame('', trim($this->renderBlock($landing, 'intro')));
        $landing->is_active = true;
        $this->assertSame('', trim($this->renderBlock($landing, 'body')));
        $this->assertSame('', trim($this->renderBlock(null, 'intro')));
    }

    private function renderBlock(?VenueCatalogLanding $landing, string $position, int $page = 1): string
    {
        return view('partials.venues.catalog-landing-editorial', [
            'landing' => $landing,
            'editorialPosition' => $position,
            'editorialClass' => 'app-venue-description',
            'editorialPage' => $page,
        ])->render();
    }
}
