<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_product_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 180);
            $table->string('code', 100)->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(100);
            $table->timestamps();
        });

        Schema::create('shop_attribute_product_type', function (Blueprint $table): void {
            $table->foreignId('attribute_id')->constrained('shop_attributes')->cascadeOnDelete();
            $table->foreignId('product_type_id')->constrained('shop_product_types')->cascadeOnDelete();
            $table->primary(['attribute_id', 'product_type_id']);
            $table->index('product_type_id');
        });

        Schema::table('shop_categories', function (Blueprint $table): void {
            $table->foreignId('default_product_type_id')->nullable()
                ->constrained('shop_product_types')->nullOnDelete();
        });

        Schema::table('shop_products', function (Blueprint $table): void {
            $table->foreignId('product_type_id')->nullable()
                ->constrained('shop_product_types')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shop_products', fn (Blueprint $table) => $table->dropConstrainedForeignId('product_type_id'));
        Schema::table('shop_categories', fn (Blueprint $table) => $table->dropConstrainedForeignId('default_product_type_id'));
        Schema::dropIfExists('shop_attribute_product_type');
        Schema::dropIfExists('shop_product_types');
    }
};
