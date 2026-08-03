<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venue_characteristics', function (Blueprint $table): void {
            $table->string('marking_condition', 20)->nullable()->after('surface_condition');
        });

        DB::table('venue_characteristics')
            ->whereNull('marking_condition')
            ->update(['marking_condition' => DB::raw('first_hoop_marking')]);
    }

    public function down(): void
    {
        Schema::table('venue_characteristics', function (Blueprint $table): void {
            $table->dropColumn('marking_condition');
        });
    }
};
