<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('design_theme_code_assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('design_theme_id')->constrained('design_themes')->cascadeOnDelete();
            $table->string('kind', 3);
            $table->longText('draft_source')->nullable();
            $table->longText('published_source')->nullable();
            $table->string('published_hash', 64)->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['design_theme_id', 'kind']);
        });

        Schema::create('design_theme_code_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('design_theme_code_asset_id')->constrained('design_theme_code_assets')->cascadeOnDelete();
            $table->string('action', 20);
            $table->longText('source')->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->boolean('is_enabled');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('design_theme_code_revisions');
        Schema::dropIfExists('design_theme_code_assets');
    }
};
