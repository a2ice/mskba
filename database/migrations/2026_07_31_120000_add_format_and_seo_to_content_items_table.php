<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_items', function (Blueprint $table): void {
            $table->string('content_format', 24)->default('markdown')->after('full_description');
            $table->string('meta_title')->nullable()->after('link_url');
            $table->string('meta_description', 320)->nullable()->after('meta_title');
            $table->text('meta_keywords')->nullable()->after('meta_description');
        });
    }

    public function down(): void
    {
        Schema::table('content_items', function (Blueprint $table): void {
            $table->dropColumn([
                'content_format',
                'meta_title',
                'meta_description',
                'meta_keywords',
            ]);
        });
    }
};
