<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table): void {
            $table->foreignId('default_venue_id')
                ->nullable()
                ->after('created_by_actor_id')
                ->constrained('venues')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tournaments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('default_venue_id');
        });
    }
};
