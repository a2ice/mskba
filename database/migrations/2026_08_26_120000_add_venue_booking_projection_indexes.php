<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venue_bookings', function (Blueprint $table): void {
            $table->index(['requester_user_id', 'flow', 'updated_at'], 'venue_bookings_requester_inbox');
            $table->index(['flow', 'venue_id', 'status', 'updated_at'], 'venue_bookings_owner_inbox');
        });
    }

    public function down(): void
    {
        Schema::table('venue_bookings', function (Blueprint $table): void {
            $table->dropIndex('venue_bookings_requester_inbox');
            $table->dropIndex('venue_bookings_owner_inbox');
        });
    }
};
