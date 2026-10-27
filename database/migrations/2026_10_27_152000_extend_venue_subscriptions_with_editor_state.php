<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('venue_subscriptions', function (Blueprint $table): void {
            $table->string('name', 80)->nullable();
            $table->json('criteria')->nullable();
            $table->boolean('notify_venue_news')->default(true);
            $table->foreignId('play_intent_id')->nullable()->unique()
                ->constrained('play_intents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('venue_subscriptions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('play_intent_id');
            $table->dropColumn(['name', 'criteria', 'notify_venue_news']);
        });
    }
};
