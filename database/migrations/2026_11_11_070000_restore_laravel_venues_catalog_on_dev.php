<?php

use App\Modules\Content\Application\Services\PageBuilderPublisher;
use App\Modules\Content\Domain\Models\PageBuilderPage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Staging /venues is currently intercepted by a published PageBuilder page.
 * Switch back to the tested Laravel catalog without deleting the CMS snapshot.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! app()->environment('staging')
            || config('app.url') !== 'https://dev.mskba.ru'
            || ! Schema::hasTable('page_builder_pages')) {
            return;
        }

        $page = PageBuilderPage::query()
            ->where('published_path', '/venues')
            ->where('published_route_name', 'venues')
            ->where('published_route_type', 'public_index')
            ->where('cms_override_enabled', true)
            ->whereNotNull('published_at')
            ->first();

        if ($page !== null) {
            // Official reversible operation: retains the published page and
            // writes an override_off audit revision for later restoration.
            app(PageBuilderPublisher::class)->setOverrideEnabled($page, false);
        }
    }

    public function down(): void
    {
        // Never override a later administrator's choice. The original published
        // page can be re-enabled through the PageBuilder editor.
    }
};
