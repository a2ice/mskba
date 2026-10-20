<?php

namespace Tests\Feature;

use App\Modules\Content\Domain\Models\PageBuilderComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PageBuilderVenuesMapV2Test extends TestCase
{
    use RefreshDatabase;

    public function test_new_map_component_has_popup_mount_and_no_fake_rating_or_booking_status(): void
    {
        $html = file_get_contents(resource_path('page-builder/venues-map.html'));
        $javascript = file_get_contents(resource_path('page-builder/venues-map.js'));
        $css = file_get_contents(resource_path('page-builder/venues-map.css'));

        $this->assertStringContainsString('id="mskba-venues-map-card"', $html);
        $this->assertStringContainsString("marker.events.add('click'", $javascript);
        $this->assertStringContainsString("cardMount.replaceChildren(makeCard(", $javascript);
        $this->assertStringNotContainsString('balloonContent:', $javascript);
        $this->assertStringNotContainsString('openCard(items[0].venue,items[0].marker)', $javascript);
        $this->assertStringContainsString('openCard(venue,marker)', $javascript);
        $this->assertStringContainsString(
            'openCard(items[0].venue,items[0].marker)',
            file_get_contents(resource_path('page-builder/versions/venues-map-v2.js')),
        );
        $this->assertStringNotContainsString('★ 4.8', $javascript);
        $this->assertStringNotContainsString("'Открыто'", $javascript);
        $this->assertStringContainsString('Фото площадки пока нет', $javascript);
        $this->assertStringContainsString('.mskba-venues-map__venue-card', $css);
    }

    public function test_previous_v1_sources_are_preserved_as_exact_guarded_baseline(): void
    {
        foreach (['html', 'css', 'js'] as $extension) {
            $this->assertFileExists(resource_path("page-builder/versions/venues-map-v1.{$extension}"));
            $this->assertNotSame(
                file_get_contents(resource_path("page-builder/versions/venues-map-v1.{$extension}")),
                file_get_contents(resource_path("page-builder/venues-map.{$extension}")),
            );
        }
    }

    public function test_publication_command_refuses_non_staging_environment(): void
    {
        PageBuilderComponent::query()->create([
            'slug' => 'venues-map', 'name' => 'Карта площадок',
            'draft_html' => '<section></section>', 'draft_css' => '',
        ]);

        $this->artisan('mskba:venues-map:publish-v2', ['--apply' => true])
            ->assertExitCode(1);

        $this->assertNull(PageBuilderComponent::query()->firstOrFail()->published_at);
    }
}
