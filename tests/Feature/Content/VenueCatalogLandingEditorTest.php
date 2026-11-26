<?php

namespace Tests\Feature\Content;

use App\Filament\Backoffice\Resources\VenueCatalogLandings\VenueCatalogLandingResource;
use App\Modules\Content\Application\Services\PageSeoResolver;
use App\Modules\Content\Application\Services\VenueCatalogLandingEditor;
use App\Modules\Content\Domain\Enums\SeoEntityTypeEnum;
use App\Modules\Content\Domain\Models\PageSeoSetting;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class VenueCatalogLandingEditorTest extends TestCase
{
    use RefreshDatabase;

    public function test_editor_requires_content_permission(): void
    {
        $this->actingAs(User::factory()->create());
        Gate::define('manage-content', fn (User $user): bool => false);
        $this->assertFalse(VenueCatalogLandingResource::canViewAny());
        $this->assertFalse(VenueCatalogLandingResource::canCreate());
        $this->expectException(AuthorizationException::class);
        app(VenueCatalogLandingEditor::class)->save($this->data());
    }

    public function test_editor_sanitizes_content_and_reuses_existing_seo_settings(): void
    {
        $this->actingAs(User::factory()->create());
        Gate::define('manage-content', fn (User $user): bool => true);
        $this->assertTrue(VenueCatalogLandingResource::canViewAny());
        $editor = app(VenueCatalogLandingEditor::class);
        $landing = $editor->save($this->data());
        $this->assertFalse($landing->is_active);
        $this->assertFalse($landing->allow_indexing);
        $this->assertStringContainsString('<p>Intro</p>', $landing->intro_text);
        $this->assertStringNotContainsString('onclick', $landing->intro_text);
        $this->assertStringNotContainsString('<script', $landing->body_text);

        $meta = app(PageSeoResolver::class)->resolve(
            SeoEntityTypeEnum::VENUE_CATALOG_LANDING, $landing->id,
            'Fallback', null, 'https://example.test/venues/rent/test',
        );
        $this->assertSame('Rental title', $meta['metaTitle']);
        $this->assertSame('Rental description', $meta['metaDescription']);

        $editor->save(array_replace($this->data(), ['meta_title' => 'Updated title']), $landing);
        $this->assertSame(1, PageSeoSetting::query()->where('entity_type', 'venue_catalog_landing')->where('entity_id', $landing->id)->count());
        $this->assertDatabaseHas('page_seo_settings', [
            'entity_type' => 'venue_catalog_landing', 'entity_id' => $landing->id,
            'meta_title' => 'Updated title',
        ]);
    }

    public function test_editor_saves_multiple_types_and_can_clear_the_filter(): void
    {
        $this->actingAs(User::factory()->create());
        Gate::define('manage-content', fn (User $user): bool => true);
        $editor = app(VenueCatalogLandingEditor::class);
        $landing = $editor->save(array_replace($this->data(), ['type' => ['sports_hall', 'sports_complex']]));
        $this->assertSame(['sports_complex', 'sports_hall'], $landing->fresh()->criteria()['type']);
        $editor->save(array_replace($this->data(), ['type' => []]), $landing);
        $this->assertArrayNotHasKey('type', $landing->fresh()->criteria());
        $this->assertTrue($landing->fresh()->rental_configured);
    }

    public function test_related_references_can_be_saved_and_cleared_but_not_point_to_self(): void
    {
        $this->actingAs(User::factory()->create());
        Gate::define('manage-content', fn (User $user): bool => true);
        $editor = app(VenueCatalogLandingEditor::class);
        $target = $editor->save(array_replace($this->data(), ['slug' => 'rent/other']));
        $landing = $editor->save(array_replace($this->data(), ['related_landing_ids' => [(string) $target->id]]));
        $this->assertSame([$target->id], $landing->fresh()->related_landing_ids);
        $editor->save(array_replace($this->data(), ['related_landing_ids' => []]), $landing);
        $this->assertSame([], $landing->fresh()->related_landing_ids);
        $this->expectException(ValidationException::class);
        $editor->save(array_replace($this->data(), ['related_landing_ids' => [$landing->id]]), $landing);
    }

    public function test_duplicate_active_combination_is_reported_as_validation_error(): void
    {
        $this->actingAs(User::factory()->create());
        Gate::define('manage-content', fn (User $user): bool => true);
        $editor = app(VenueCatalogLandingEditor::class);
        $editor->save(array_replace($this->data(), ['is_active' => true]));

        $this->expectException(ValidationException::class);
        $editor->save(array_replace($this->data(), ['slug' => 'rent/duplicate', 'is_active' => true]));
    }

    private function data(): array
    {
        return [
            'name' => 'Rental test', 'slug' => 'rent/test', 'h1' => 'Rental',
            'type' => 'sports_hall', 'rental_configured' => true,
            'is_active' => false, 'allow_indexing' => false, 'include_in_sitemap' => false,
            'intro_text' => '<p onclick="alert(1)">Intro</p>',
            'body_text' => '<p>Body</p><script>alert(1)</script>',
            'meta_title' => 'Rental title', 'meta_description' => 'Rental description',
        ];
    }
}
