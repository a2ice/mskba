<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_chats', function (Blueprint $table): void {
            $table->boolean('publishes_events')->default(true)->after('publishes_coordination');
        });

        Schema::table('telegram_event_publications', function (Blueprint $table): void {
            $table->dropUnique('telegram_event_publications_event_id_unique');
            $table->unique(['event_id', 'chat_id'], 'telegram_event_publications_event_chat_unique');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_event_publications', function (Blueprint $table): void {
            $table->dropUnique('telegram_event_publications_event_chat_unique');
        });

        DB::table('telegram_event_publications')
            ->whereNotIn('id', DB::table('telegram_event_publications')->selectRaw('MIN(id)')->groupBy('event_id'))
            ->delete();

        Schema::table('telegram_event_publications', function (Blueprint $table): void {
            $table->unique('event_id');
        });

        Schema::table('telegram_chats', function (Blueprint $table): void {
            $table->dropColumn('publishes_events');
        });
    }
};
