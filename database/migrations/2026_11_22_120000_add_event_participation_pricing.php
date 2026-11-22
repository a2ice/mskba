<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->boolean('is_paid')->default(false);
            $table->unsignedBigInteger('total_cost_minor')->nullable();
            $table->unsignedBigInteger('participant_cost_minor')->nullable();
            $table->boolean('participant_cost_auto')->default(true);
            $table->text('cost_comment')->nullable();
            $table->text('time_comment')->nullable();
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->text('default_event_cost_comment')->nullable();
            $table->text('default_event_time_comment')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['default_event_cost_comment', 'default_event_time_comment']));
        Schema::table('events', fn (Blueprint $table) => $table->dropColumn([
            'is_paid', 'total_cost_minor', 'participant_cost_minor', 'participant_cost_auto', 'cost_comment', 'time_comment',
        ]));
    }
};
