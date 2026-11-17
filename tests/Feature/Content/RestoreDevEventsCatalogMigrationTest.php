<?php

namespace Tests\Feature\Content;

use App\Modules\Content\Application\Services\PageBuilderPublisher;
use App\Modules\Content\Domain\Models\PageBuilderPage;
use App\Modules\Content\Domain\Models\PageBuilderRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RestoreDevEventsCatalogMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_11_12_150000_restore_laravel_events_catalog_on_dev.php';

    public function test_only_exact_dev_environment_disables_events_override_and_preserves_cms_content(): void
    {
        $page = $this->publishedEventsOverride();
        $original = $page->published_html;

        $this->switchEnvironment('staging', 'https://dev.mskba.ru');
        try {
            $this->migration()->up();
            $this->migration()->up(); // Idempotent on repeated execution.
        } finally {
            $this->switchEnvironment('testing', 'http://localhost');
        }

        $this->assertFalse($page->fresh()->cms_override_enabled);
        $this->assertSame($original, $page->fresh()->published_html);
        $this->assertSame(1, PageBuilderRevision::query()
            ->where('entity_type', 'page')
            ->where('entity_id', $page->id)
            ->where('action', 'override_off')
            ->count());

        app(PageBuilderPublisher::class)->setOverrideEnabled($page->fresh(), true);
        $this->assertTrue($page->fresh()->cms_override_enabled);
        $this->assertSame($original, $page->fresh()->published_html);
    }

    public function test_prod_host_and_non_staging_environment_never_change_published_override(): void
    {
        $page = $this->publishedEventsOverride();

        $this->switchEnvironment('production', 'https://dev.mskba.ru');
        try {
            $this->migration()->up();
        } finally {
            $this->switchEnvironment('testing', 'http://localhost');
        }
        $this->assertTrue($page->fresh()->cms_override_enabled);

        $this->switchEnvironment('staging', 'https://mskba.ru');
        try {
            $this->migration()->up();
        } finally {
            $this->switchEnvironment('testing', 'http://localhost');
        }
        $this->assertTrue($page->fresh()->cms_override_enabled);
        $this->assertDatabaseMissing('page_builder_revisions', [
            'entity_type' => 'page',
            'entity_id' => $page->id,
            'action' => 'override_off',
        ]);
    }

    private function publishedEventsOverride(): PageBuilderPage
    {
        $page = PageBuilderPage::query()->firstOrCreate(
            ['path' => '/events'],
            ['name' => 'Тестовая публикация CMS', 'draft_html' => '<h1>Тестовая публикация</h1>'],
        );
        $page->forceFill([
            'published_path' => '/events',
            'published_route_name' => 'events.index',
            'published_route_type' => 'public_index',
            'cms_override_enabled' => true,
            'published_at' => now(),
            'published_html' => '<h1>Оригинальный HTML Page Builder</h1>',
        ])->save();

        return $page->fresh();
    }

    private function migration(): object
    {
        return require database_path('migrations/'.self::MIGRATION);
    }

    private function switchEnvironment(string $environment, string $url): void
    {
        $this->app->detectEnvironment(static fn (): string => $environment);
        config(['app.url' => $url]);
    }
}
