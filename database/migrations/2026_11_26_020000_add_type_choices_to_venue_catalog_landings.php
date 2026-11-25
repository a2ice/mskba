<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venue_catalog_landings', function (Blueprint $table): void {
            $table->json('type_choices')->nullable();
        });
    }

    public function down(): void
    {
        // Do not silently broaden an existing multi-type landing on rollback.
        if (\Illuminate\Support\Facades\DB::table('venue_catalog_landings')->whereNotNull('type_choices')->exists()) {
            throw new \RuntimeException('Deactivate and explicitly convert multi-type landings before rollback.');
        }
        Schema::table('venue_catalog_landings', function (Blueprint $table): void {
            $table->dropColumn('type_choices');
        });
    }
};
