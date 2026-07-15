<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venues', function (Blueprint $table): void {
            $table
                ->foreignId('canonical_venue_id')
                ->nullable()
                ->after('location_id')
                ->constrained('venues')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('venues', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('canonical_venue_id');
        });
    }
};
