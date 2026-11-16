<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venue_integration_sources', function (Blueprint $table): void {
            // null = unknown, 0 = no hold support, > 0 = provider cap in minutes.
            $table->unsignedSmallInteger('max_hold_duration_minutes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('venue_integration_sources', function (Blueprint $table): void {
            $table->dropColumn('max_hold_duration_minutes');
        });
    }
};
