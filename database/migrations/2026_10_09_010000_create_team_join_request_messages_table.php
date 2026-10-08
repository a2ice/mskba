<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_join_request_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_join_request_id')->constrained('team_join_requests')->cascadeOnDelete();
            $table->foreignId('sender_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->index(['team_join_request_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_join_request_messages');
    }
};
