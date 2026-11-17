<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Lighting, a safety barrier and hoop nets are not outdoor-only: both
     * indoor halls and outdoor courts may have them. Existing venue links
     * are preserved. Keep all other indoor/outdoor restrictions unchanged.
     */
    public function up(): void
    {
        DB::table('amenities')
            ->whereIn('alias', ['lighting', 'fence', 'hoop-nets'])
            ->where('applies_to', 'outdoor')
            ->update(['applies_to' => 'all', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('amenities')
            ->whereIn('alias', ['lighting', 'fence', 'hoop-nets'])
            ->where('applies_to', 'all')
            ->update(['applies_to' => 'outdoor', 'updated_at' => now()]);
    }
};
