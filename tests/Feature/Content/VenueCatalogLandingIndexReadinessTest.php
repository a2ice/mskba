<?php

namespace Tests\Feature\Content;

use App\Modules\Content\Application\Services\VenueCatalogLandingIndexReadiness;
use App\Modules\Content\Domain\Models\PageSeoSetting;
use App\Modules\Content\Domain\Models\VenueCatalogLanding;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

final class VenueCatalogLandingIndexReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_checklist_reports_missing_fields_without_changing_flags(): void
    {
        $this->actingAs(User::factory()->create());
        Gate::define('manage-content', fn (User $user): bool => true);
        $landing = VenueCatalogLanding::query()->create([
            'name' => 'Readiness', 'h1' => 'Readiness', 'slug' => 'rent/readiness',
            'type' => 'sports_hall', 'intro_text' => '<p>&nbsp;<br></p>',
            'body_text' => '<p>Useful text</p>',
            'include_in_sitemap' => true,
        ]);
        $service = app(VenueCatalogLandingIndexReadiness::class);

        $this->assertSame([
            'checks' => [
                'active' => false, 'nonempty' => false, 'intro' => false,
                'body' => true, 'meta_title' => false, 'meta_description' => false,
                'index_requested' => false,
            ],
            'sitemap_requested' => true,
        ], $service->inspect($landing, 0));
        $this->assertFalse($landing->fresh()->allow_indexing);
        $this->assertFalse($landing->fresh()->is_active);

        $landing->update(['is_active' => true, 'allow_indexing' => true, 'intro_text' => '<p>Introduction</p>']);
        PageSeoSetting::query()->create([
            'entity_type' => 'venue_catalog_landing', 'entity_id' => $landing->id,
            'meta_title' => 'Rental title', 'meta_description' => 'Rental description',
        ]);

        $result = $service->inspect($landing, 3);
        $this->assertNotContains(false, $result['checks']);
        $this->assertTrue($result['sitemap_requested']);
    }

    public function test_checklist_requires_editor_permission(): void
    {
        $this->actingAs(User::factory()->create());
        Gate::define('manage-content', fn (User $user): bool => false);
        $this->expectException(AuthorizationException::class);
        app(VenueCatalogLandingIndexReadiness::class)->inspect(new VenueCatalogLanding, 0);
    }
}
