<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('venue_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('venue_id')->constrained('venues')->cascadeOnDelete();
            $table->string('status', 16)->default('active');
            $table->json('delivery_channels')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'venue_id']);
            $table->index(['user_id', 'status']);
        });

        // Preserve existing favorite-venue notification opt-ins as real subscriptions.
        DB::table('favorites')->where('entity_type', 'venue')
            ->whereNull('trashed_at')->where('notify_upcoming_events', true)
            ->whereNotNull('user_id')->orderBy('id')->chunkById(200, function ($favorites): void {
                $rows = [];
                foreach ($favorites as $favorite) {
                    $rows[] = [
                        'user_id' => $favorite->user_id,
                        'venue_id' => $favorite->entity_id,
                        'status' => 'active',
                        'delivery_channels' => json_encode(['in_app']),
                        'created_at' => $favorite->created_at ?? now(),
                        'updated_at' => now(),
                    ];
                }
                DB::table('venue_subscriptions')->insertOrIgnore($rows);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_subscriptions');
    }
};
