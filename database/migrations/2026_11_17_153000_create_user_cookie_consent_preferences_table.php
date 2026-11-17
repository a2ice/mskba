<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_cookie_consent_preferences', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->string('analytics_choice', 16);
            $table->string('notice_version', 40);
            $table->timestampTz('updated_at');
        });

        // Preserve decisions already recorded for signed-in users by the
        // earlier cookie-only implementation. The ledger is pruned separately;
        // account preferences must remain after evidence retention expires.
        DB::table('cookie_consent_events as events')
            ->join('users', 'events.user_id', '=', 'users.id')
            ->select('events.id', 'events.analytics_choice',
                'events.notice_version', 'events.created_at')
            // Preserve prior acceptance even for an account which was later
            // merged into a canonical MSKBA user.
            ->selectRaw('COALESCE(users.canonical_user_id, users.id) AS user_id')
            ->chunkById(500, function ($events): void {
                $latestPerUser = [];
                foreach ($events as $event) {
                    $latestPerUser[(int) $event->user_id] = [
                        'user_id' => $event->user_id,
                        'analytics_choice' => $event->analytics_choice,
                        'notice_version' => $event->notice_version,
                        'updated_at' => $event->created_at,
                    ];
                }
                if ($latestPerUser !== []) {
                    DB::table('user_cookie_consent_preferences')->upsert(
                        array_values($latestPerUser),
                        ['user_id'],
                        ['analytics_choice', 'notice_version', 'updated_at'],
                    );
                }
            }, 'events.id', 'id');
    }

    public function down(): void
    {
        Schema::dropIfExists('user_cookie_consent_preferences');
    }
};
