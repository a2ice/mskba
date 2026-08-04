<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SESSION_TITLE = 'Турнир по стритболу между командами (3+ человека) где-нибудь в середине августа';

    public function up(): void
    {
        $sessionId = DB::table('coordination_sessions')
            ->where('title', self::SESSION_TITLE)
            ->orderByDesc('id')
            ->value('id');

        if ($sessionId !== null) {
            DB::table('coordination_polls')
                ->where('session_id', $sessionId)
                ->update(['allows_vote_changes' => true, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Не откатываем пользовательское решение: после deploy настройка могла
        // быть изменена повторно, и технический rollback не должен её затереть.
    }
};
