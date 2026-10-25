<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Early backoffice records used these exact aliases as the court type,
        // but did not persist the separate environment field.
        // Only repair unclassified, non-deleted courts with unambiguous aliases.
        foreach (['outdoor', 'indoor'] as $environment) {
            DB::table('venue_courts')
                ->whereNull('deleted_at')
                ->whereNull('environment')
                ->where('alias', $environment)
                ->update(['environment' => $environment]);
        }
    }

    public function down(): void
    {
        // Do not erase classifications: their provenance cannot be distinguished
        // from administrator edits after this data migration has run.
    }
};
