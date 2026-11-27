<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venue_assigned_managers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venue_id')->constrained('venues')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('ended_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamp('ended_at')->nullable();
            $table->boolean('active_marker')->nullable()->default(true);
            $table->timestamps();
            // SQL permits multiple NULLs: retain history, but only one current assignment.
            $table->unique(['venue_id', 'active_marker'], 'venue_assigned_managers_current_unique');
            $table->index(['user_id', 'active_marker']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_assigned_managers');
    }
};
