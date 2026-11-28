<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_invitation_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('game_admission_id')->constrained('game_admissions')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('notified_by_user_id')->constrained('users')->restrictOnDelete();
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->unique(['game_admission_id', 'user_id']);
        });
    }

    public function down(): void
    {
        if (DB::table('game_invitation_recipients')->exists()) {
            throw new \RuntimeException('Game invitation recipient history must be preserved.');
        }
        Schema::dropIfExists('game_invitation_recipients');
    }
};
