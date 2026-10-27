<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('play_intents', function (Blueprint $table): void {
            $table->json('offers_delivery_channels')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('play_intents', function (Blueprint $table): void {
            $table->dropColumn('offers_delivery_channels');
        });
    }
};
