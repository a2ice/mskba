<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_participants', function (Blueprint $table): void {
            // Event-specific activity, independent of organizer/participant ACL.
            // NULL retains the meaning of old participation records.
            $table->string('participation_role', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('event_participants', function (Blueprint $table): void {
            $table->dropColumn('participation_role');
        });
    }
};
