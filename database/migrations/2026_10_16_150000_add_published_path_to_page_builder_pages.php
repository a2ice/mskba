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
            $table->string('published_path', 255)->nullable()->unique();
        });

        // Preserve every existing published URL; unpublished drafts stay unbound.
        DB::table('page_builder_pages')
            ->whereNotNull('published_at')
            ->update(['published_path' => DB::raw('path')]);
    }

    public function down(): void
    {
        Schema::table('page_builder_pages', function (Blueprint $table): void {
            $table->dropUnique(['published_path']);
            $table->dropColumn('published_path');
        });
    }
};
