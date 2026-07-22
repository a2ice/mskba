<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('events')->where('type', 'tournament')->exists()) {
            throw new RuntimeException('Нельзя удалить тип tournament: в базе существуют мероприятия этого типа.');
        }

        $this->replaceTypeConstraint(['game', 'training', 'game_training']);
    }

    public function down(): void
    {
        $this->replaceTypeConstraint(['game', 'training', 'game_training', 'tournament']);
    }

    /** @param list<string> $types */
    private function replaceTypeConstraint(array $types): void
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $allowed = implode(', ', array_map(
            fn (string $type): string => $connection->getPdo()->quote($type),
            $types,
        ));

        DB::statement('ALTER TABLE events DROP CONSTRAINT IF EXISTS events_type_check');
        DB::statement("ALTER TABLE events ADD CONSTRAINT events_type_check CHECK (type IN ({$allowed}))");
    }
};
