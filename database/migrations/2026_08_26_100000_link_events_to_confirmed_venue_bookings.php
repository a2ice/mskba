<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->foreignId('booking_id')->nullable()->unique()->after('venue_id')->constrained('venue_bookings')->restrictOnDelete();
            $table->json('booking_snapshot')->nullable()->after('booking_id');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropUnique(['booking_id']);
            $table->dropConstrainedForeignId('booking_id');
            $table->dropColumn('booking_snapshot');
        });
    }
};
