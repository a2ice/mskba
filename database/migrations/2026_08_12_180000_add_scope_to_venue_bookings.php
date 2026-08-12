<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venue_bookings', function (Blueprint $table): void {
            $table->string('scope', 16)->default('whole')->after('status');
            $table->index(['venue_id', 'scope', 'status', 'starts_at'], 'venue_booking_scope_availability');
        });
    }

    public function down(): void
    {
        Schema::table('venue_bookings', function (Blueprint $table): void {
            $table->dropIndex('venue_booking_scope_availability');
            $table->dropColumn('scope');
        });
    }
};
