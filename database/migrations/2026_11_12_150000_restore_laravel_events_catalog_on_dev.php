<?php

use App\Modules\Content\Application\Services\PageBuilderPublisher;
use App\Modules\Content\Domain\Models\PageBuilderPage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Task 316: the dev /events route currently serves a published CMS placeholder
 * instead of the real Laravel event catalog. Retain the CMS publication and
 * its revision history; only disable its opt-in route override on isolated dev.
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
            ->where('published_path', '/events')
            ->where('published_route_name', 'events.index')
            ->where('published_route_type', 'public_index')
            ->where('cms_override_enabled', true)
            ->whereNotNull('published_at')
            ->first();

        if ($page !== null) {
            // Existing reversible editor operation writes an override_off
            // revision, without deleting or unpublishing CMS page content.
            app(PageBuilderPublisher::class)->setOverrideEnabled($page, false);
        }
    }

    public function down(): void
    {
        // Do not unexpectedly override a future editor decision.
        // Restore manually in Page Builder if explicitly requested.
    }
};
