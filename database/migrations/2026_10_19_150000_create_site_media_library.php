<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_media_folders', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150);
            $table->string('slug', 100)->unique();
            $table->timestamps();
        });

        Schema::create('site_media_assets', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('folder_id')->nullable()->constrained('site_media_folders')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->string('alt', 180)->nullable();
            $table->string('original_name', 255)->nullable();
            $table->string('legacy_path', 512)->nullable()->unique();
            $table->unsignedBigInteger('current_media_id')->nullable();
            $table->unsignedBigInteger('previous_media_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('folder_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_media_assets');
        Schema::dropIfExists('site_media_folders');
    }
};
