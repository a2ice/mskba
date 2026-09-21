<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('pricing_services', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->constrained('pricing_categories')->restrictOnDelete();
            $table->string('code', 96)->unique();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['category_id', 'is_active', 'sort_order']);
        });

        Schema::create('pricing_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_id')->constrained('pricing_services')->restrictOnDelete();
            $table->string('code', 96);
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['service_id', 'code']);
            $table->index(['service_id', 'is_active', 'sort_order']);
        });

        Schema::create('pricing_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_id')->constrained('pricing_services')->restrictOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('pricing_variants')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3)->default('RUB');
            $table->dateTime('valid_from');
            $table->dateTime('valid_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['service_id', 'variant_id', 'currency'], 'pricing_prices_target_idx');
            $table->index(['is_active', 'valid_from', 'valid_until'], 'pricing_prices_period_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_prices');
        Schema::dropIfExists('pricing_variants');
        Schema::dropIfExists('pricing_services');
        Schema::dropIfExists('pricing_categories');
    }
};
