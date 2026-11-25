<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venue_catalog_landings', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug', 180)->unique();
            $table->string('h1');
            $table->foreignId('city_id')->nullable()->constrained('cities')->restrictOnDelete();
            $table->foreignId('district_id')->nullable()->constrained('districts')->restrictOnDelete();
            $table->string('type', 32)->nullable();
            $table->string('operational_status', 32)->nullable();
            $table->string('access', 32)->nullable();
            $table->boolean('rental_configured')->default(false);
            $table->text('intro_text')->nullable();
            $table->text('body_text')->nullable();
            $table->boolean('is_active')->default(false);
            $table->boolean('allow_indexing')->default(false);
            $table->boolean('include_in_sitemap')->default(false);
            // Only active rows reserve a combination. Drafts may overlap.
            $table->string('active_filter_signature', 64)->nullable()->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_catalog_landings');
    }
};
