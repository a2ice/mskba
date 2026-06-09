<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addresses', function (Blueprint $table): void {
            $table->id();
            $table->string('postal_code')->nullable();
            $table->string('city');
            $table->string('street')->nullable();
            $table->string('building')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->text('full_address')->nullable();
            $table->timestamps();
        });

        Schema::create('locations', function (Blueprint $table): void {
            $table->id();
            $table
                ->foreignId('address_id')
                ->nullable()
                ->constrained('addresses')
                ->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('metro_lines', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('color', 32)->nullable();
            $table->unsignedInteger('sort_order')->nullable();
            $table->timestamps();
        });

        Schema::create('metro_stations', function (Blueprint $table): void {
            $table->id();
            $table
                ->foreignId('metro_line_id')
                ->constrained('metro_lines')
                ->cascadeOnDelete();
            $table->string('name');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('sort_order')->nullable();
            $table->timestamps();

            $table->unique(['metro_line_id', 'name']);
        });

        Schema::create('location_metro_station', function (Blueprint $table): void {
            $table->id();
            $table
                ->foreignId('location_id')
                ->constrained('locations')
                ->cascadeOnDelete();
            $table
                ->foreignId('metro_station_id')
                ->constrained('metro_stations')
                ->cascadeOnDelete();
            $table->unsignedInteger('distance_meters')->nullable();
            $table->unsignedInteger('walking_time_minutes')->nullable();
            $table->timestamps();

            $table->unique(['location_id', 'metro_station_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_metro_station');
        Schema::dropIfExists('metro_stations');
        Schema::dropIfExists('metro_lines');
        Schema::dropIfExists('locations');
        Schema::dropIfExists('addresses');
    }
};
