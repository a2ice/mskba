<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_builder_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('name', 150);
            $table->longText('draft_html')->default('');
            $table->longText('draft_css')->nullable();
            $table->longText('published_html')->nullable();
            $table->longText('published_css')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('page_builder_components', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('name', 150);
            $table->string('description', 500)->nullable();
            $table->longText('draft_html')->default('');
            $table->longText('draft_css')->nullable();
            $table->longText('published_html')->nullable();
            $table->longText('published_css')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('page_builder_pages', function (Blueprint $table): void {
            $table->id();
            $table->string('path', 255)->unique();
            $table->string('name', 150);
            $table->string('meta_title', 180)->nullable();
            $table->string('meta_description', 500)->nullable();
            $table->foreignId('template_id')->nullable()->constrained('page_builder_templates')->nullOnDelete();
            $table->longText('draft_html')->default('');
            $table->longText('draft_css')->nullable();
            $table->longText('published_html')->nullable();
            $table->longText('published_css')->nullable();
            $table->string('published_name', 150)->nullable();
            $table->string('published_meta_title', 180)->nullable();
            $table->string('published_meta_description', 500)->nullable();
            $table->foreignId('published_template_id')->nullable()->constrained('page_builder_templates')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('page_builder_revisions', function (Blueprint $table): void {
            $table->id();
            $table->string('entity_type', 16);
            $table->unsignedBigInteger('entity_id');
            $table->string('action', 16);
            $table->json('snapshot');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
            $table->index(['entity_type', 'entity_id', 'id'], 'pb_revisions_entity_idx');
        });

        $now = now();
        DB::table('page_builder_templates')->insert([
            'slug' => 'base', 'name' => 'Базовый контентный шаблон',
            'draft_html' => '<section class="mskba-builder-section">[[page_content]]</section>',
            'published_html' => '<section class="mskba-builder-section">[[page_content]]</section>',
            'published_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $templateId = DB::table('page_builder_templates')->where('slug', 'base')->value('id');
        DB::table('page_builder_pages')->insert([
            'path' => '/', 'name' => 'Главная',
            'meta_title' => 'MSKBA — баскетбол в Москве',
            'meta_description' => 'Игры, турниры, команды и баскетбольные площадки Москвы.',
            'template_id' => $templateId,
            'draft_html' => '<h1>MSKBA — баскетбол в Москве</h1><p>Главная страница в разработке.</p>',
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('page_builder_revisions');
        Schema::dropIfExists('page_builder_pages');
        Schema::dropIfExists('page_builder_components');
        Schema::dropIfExists('page_builder_templates');
    }
};
