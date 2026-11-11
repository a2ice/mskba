<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_builder_pages', function (Blueprint $table): void {
            $table->foreignId('design_theme_id')->nullable()
                ->after('path')->constrained('design_themes')->restrictOnDelete();
            // Draft selection must not move a live page until publication.
            $table->foreignId('published_design_theme_id')->nullable()
                ->constrained('design_themes')->restrictOnDelete();
            $table->dropUnique('page_builder_pages_path_unique');
            $table->dropUnique('page_builder_pages_published_path_unique');
            $table->unique(['path', 'design_theme_id'], 'pb_page_path_theme_unique');
            $table->unique(['published_path', 'published_design_theme_id'], 'pb_page_published_path_theme_unique');
        });

        // PostgreSQL/SQLite both allow multiple NULLs in a composite UNIQUE.
        // These partial indexes guarantee exactly one default (all-themes)
        // page for each draft and published URL while permitting themed copies.
        DB::statement('CREATE UNIQUE INDEX pb_page_default_path_unique ON page_builder_pages (path) WHERE design_theme_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX pb_page_default_published_path_unique ON page_builder_pages (published_path) WHERE published_design_theme_id IS NULL AND published_path IS NOT NULL');
    }

    public function down(): void
    {
        // A rollback cannot drop theme scopes if overlapping URLs exist.
        // Fail safely rather than losing or silently rerouting published pages.
        $duplicates = DB::table('page_builder_pages')
            ->select('path')->groupBy('path')->havingRaw('COUNT(*) > 1')->exists();
        $publishedDuplicates = DB::table('page_builder_pages')
            ->whereNotNull('published_path')->select('published_path')
            ->groupBy('published_path')->havingRaw('COUNT(*) > 1')->exists();
        if ($duplicates || $publishedDuplicates) {
            throw new \RuntimeException('Нельзя откатить привязку Page Builder к темам: есть страницы с одинаковыми URL.');
        }

        DB::statement('DROP INDEX IF EXISTS pb_page_default_path_unique');
        DB::statement('DROP INDEX IF EXISTS pb_page_default_published_path_unique');

        Schema::table('page_builder_pages', function (Blueprint $table): void {
            $table->dropUnique('pb_page_path_theme_unique');
            $table->dropUnique('pb_page_published_path_theme_unique');
            $table->dropConstrainedForeignId('design_theme_id');
            $table->dropConstrainedForeignId('published_design_theme_id');
            $table->unique('path');
            $table->unique('published_path');
        });
    }
};
