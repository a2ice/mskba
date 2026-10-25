<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venue_courts', function (Blueprint $table): void {
            // Unknown is intentionally null, not implicitly indoor.
            $table->string('environment', 12)->nullable();
        });
        // Unambiguous existing court names can be migrated without guessing.
        DB::table('venue_courts')->where('name', 'like', 'Уличн%')->update(['environment' => 'outdoor']);
        DB::table('venue_courts')->where('name', 'like', 'Крыт%')->update(['environment' => 'indoor']);
    }

    public function down(): void
    {
        Schema::table('venue_courts', fn (Blueprint $table) => $table->dropColumn('environment'));
    }
};
