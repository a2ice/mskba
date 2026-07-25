<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coordination_event_transitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('session_id')
                ->unique()
                ->constrained('coordination_sessions')
                ->cascadeOnDelete();
            $table->foreignId('decision_id')
                ->unique()
                ->constrained('coordination_decisions')
                ->restrictOnDelete();
            $table->foreignId('event_id')
                ->unique()
                ->constrained('events')
                ->restrictOnDelete();
            $table->foreignId('created_by_actor_id')
                ->constrained('actors')
                ->restrictOnDelete();
            $table->timestampTz('transitioned_at');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coordination_event_transitions');
    }
};
