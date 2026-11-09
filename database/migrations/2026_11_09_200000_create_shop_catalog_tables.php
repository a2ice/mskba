<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('shop_categories')->restrictOnDelete();
            $table->string('name', 180);
            $table->string('slug', 190)->unique();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->unsignedInteger('sort_order')->default(100);
            $table->json('images')->nullable();
            $table->timestamps();
            $table->index(['parent_id', 'sort_order']);
        });

        Schema::create('shop_products', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 220);
            $table->string('slug', 190)->unique();
            $table->string('sku', 100)->nullable()->unique();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->string('stock_status', 20)->default('out_of_stock');
            $table->unsignedInteger('stock_quantity')->default(0);
            $table->unsignedBigInteger('price_kopecks')->nullable();
            $table->unsignedBigInteger('old_price_kopecks')->nullable();
            $table->json('images')->nullable();
            $table->unsignedInteger('sort_order')->default(100);
            $table->timestamps();
        });

        Schema::create('shop_category_product', function (Blueprint $table): void {
            $table->foreignId('category_id')->constrained('shop_categories')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('shop_products')->cascadeOnDelete();
            $table->primary(['category_id', 'product_id']);
            $table->index('product_id');
        });

        Schema::create('shop_tags', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 110)->unique();
            $table->timestamps();
        });

        Schema::create('shop_taggables', function (Blueprint $table): void {
            $table->foreignId('shop_tag_id')->constrained('shop_tags')->cascadeOnDelete();
            $table->morphs('taggable');
            $table->unique(['shop_tag_id', 'taggable_type', 'taggable_id'], 'shop_taggables_unique');
        });

        Schema::create('shop_attributes', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150);
            $table->string('code', 100)->unique();
            $table->string('type', 20)->default('text');
            $table->string('unit', 40)->nullable();
            $table->json('options')->nullable();
            $table->unsignedInteger('sort_order')->default(100);
            $table->timestamps();
        });

        Schema::create('shop_attribute_category', function (Blueprint $table): void {
            $table->foreignId('attribute_id')->constrained('shop_attributes')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('shop_categories')->cascadeOnDelete();
            $table->primary(['attribute_id', 'category_id']);
            $table->index('category_id');
        });

        Schema::create('shop_product_attribute_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('shop_products')->cascadeOnDelete();
            $table->foreignId('attribute_id')->constrained('shop_attributes')->cascadeOnDelete();
            $table->text('value')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'attribute_id'], 'shop_product_attribute_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_product_attribute_values');
        Schema::dropIfExists('shop_attribute_category');
        Schema::dropIfExists('shop_attributes');
        Schema::dropIfExists('shop_taggables');
        Schema::dropIfExists('shop_tags');
        Schema::dropIfExists('shop_category_product');
        Schema::dropIfExists('shop_products');
        Schema::dropIfExists('shop_categories');
    }
};
