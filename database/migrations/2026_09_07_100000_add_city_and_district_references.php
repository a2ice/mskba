<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cities', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('alias')->unique();
            $table->string('short_name')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('districts', function (Blueprint $table): void {
            $table->id();
            $table
                ->foreignId('city_id')
                ->constrained('cities')
                ->cascadeOnDelete();
            $table->string('name');
            $table->string('alias');
            $table->string('short_name')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['city_id', 'alias']);
            $table->unique(['city_id', 'name']);
        });

        Schema::table('addresses', function (Blueprint $table): void {
            $table
                ->foreignId('city_id')
                ->nullable()
                ->constrained('cities')
                ->nullOnDelete();
            $table
                ->foreignId('district_id')
                ->nullable()
                ->constrained('districts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('district_id');
            $table->dropConstrainedForeignId('city_id');
        });

        Schema::dropIfExists('districts');
        Schema::dropIfExists('cities');
    }
};
