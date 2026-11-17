<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_participants', function (Blueprint $table): void {
            // Multiple event-specific activities, independent of organizer/participant ACL.
            // NULL retains the meaning of old participation records.
            $table->json('participation_roles')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('event_participants', function (Blueprint $table): void {
            $table->dropColumn('participation_roles');
        });
    }
};
