<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('design_themes', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 60)->unique();
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            $table->string('mode', 8)->default('dark');
            $table->json('colors');
            $table->text('css_code')->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_published')->default(true);
            $table->timestamps();
        });

        Schema::create('design_theme_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('design_theme_id')->constrained('design_themes')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            $table->string('mode', 8);
            $table->json('colors');
            $table->text('css_code')->nullable();
            $table->boolean('is_published');
            $table->timestamp('created_at');
        });

        Schema::create('design_theme_settings', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->foreignId('theme_id')->constrained('design_themes')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('design_theme_id')->nullable()->constrained('design_themes')->nullOnDelete();
        });

        $now = now();
        $ids = [];
        foreach (config('design_palettes', []) as $preset) {
            $ids[$preset['slug']] = DB::table('design_themes')->insertGetId([
                'slug' => $preset['slug'], 'name' => $preset['name'],
                'description' => $preset['description'], 'mode' => $preset['mode'],
                'colors' => json_encode($preset['colors'], JSON_THROW_ON_ERROR),
                'css_code' => $preset['css_code'], 'is_system' => true,
                'is_published' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('design_theme_revisions')->insert([
                'design_theme_id' => $ids[$preset['slug']],
                'name' => $preset['name'],
                'description' => $preset['description'],
                'mode' => $preset['mode'],
                'colors' => json_encode($preset['colors'], JSON_THROW_ON_ERROR),
                'css_code' => $preset['css_code'],
                'is_published' => true,
                'created_at' => $now,
            ]);
        }
        DB::table('design_theme_settings')->insert([
            'id' => 1, 'theme_id' => $ids['arena_night'], 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('design_theme_id'));
        Schema::dropIfExists('design_theme_settings');
        Schema::dropIfExists('design_theme_revisions');
        Schema::dropIfExists('design_themes');
    }
};
