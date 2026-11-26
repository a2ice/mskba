<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venue_catalog_landings', function (Blueprint $table): void {
            $table->json('related_landing_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('venue_catalog_landings', function (Blueprint $table): void {
            $table->dropColumn('related_landing_ids');
        });
    }
};
