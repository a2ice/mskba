<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venue_courts', function (Blueprint $table): void {
            $table->decimal('length_meters', 6, 2)->nullable()->after('surface_type');
            $table->decimal('width_meters', 6, 2)->nullable()->after('length_meters');
        });
    }

    public function down(): void
    {
        Schema::table('venue_courts', function (Blueprint $table): void {
            $table->dropColumn(['length_meters', 'width_meters']);
        });
    }
};
