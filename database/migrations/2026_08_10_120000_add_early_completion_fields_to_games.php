<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table): void {
            $table->boolean('ended_early')->default(false)->after('actual_ended_by_actor_id');
            $table->text('status_comment')->nullable()->after('ended_early');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table): void {
            $table->dropColumn(['ended_early', 'status_comment']);
        });
    }
};
