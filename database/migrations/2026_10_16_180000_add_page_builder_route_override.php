<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_builder_pages', function (Blueprint $table): void {
            $table->string('published_route_name', 100)->nullable();
            $table->string('published_route_type', 32)->default('static');
            $table->boolean('cms_override_enabled')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('page_builder_pages', function (Blueprint $table): void {
            $table->dropColumn(['published_route_name', 'published_route_type', 'cms_override_enabled']);
        });
    }
};
