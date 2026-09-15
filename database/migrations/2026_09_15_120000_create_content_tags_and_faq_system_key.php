<?php

use Database\Seeders\FaqContentSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_items', function (Blueprint $table): void {
            $table->string('system_key', 160)->nullable()->unique()->after('alias');
            $table->string('status', 16)->default('published');
        });

        Schema::create('content_tags', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('normalized_name', 100)->unique();
            $table->timestamps();
        });

        Schema::create('content_item_tag', function (Blueprint $table): void {
            $table->foreignId('content_item_id')->constrained('content_items')->cascadeOnDelete();
            $table->foreignId('content_tag_id')->constrained('content_tags')->cascadeOnDelete();
            $table->primary(['content_item_id', 'content_tag_id']);
            $table->index(['content_tag_id', 'content_item_id']);
        });

        // Production already has users when this migration runs. Fresh installs
        // bootstrap the same FAQ records later through DatabaseSeeder.
        app(FaqContentSeeder::class)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('content_item_tag');
        Schema::dropIfExists('content_tags');

        Schema::table('content_items', function (Blueprint $table): void {
            $table->dropUnique(['system_key']);
            $table->dropColumn('system_key');
            $table->dropColumn('status');
        });
    }
};
