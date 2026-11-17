<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('favorite_notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('entity_type', 32);
            $table->unsignedBigInteger('entity_id');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['user_id', 'entity_type', 'entity_id'], 'favorite_notification_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('favorite_notification_deliveries');
    }
};
