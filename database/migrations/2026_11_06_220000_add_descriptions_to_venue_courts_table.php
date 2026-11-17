<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venue_courts', function (Blueprint $table): void {
            $table->text('short_description')->nullable();
            $table->text('full_description')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('venue_courts', function (Blueprint $table): void {
            $table->dropColumn(['short_description', 'full_description']);
        });
    }
};
