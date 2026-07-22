<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->replaceTypeConstraint(['game', 'training', 'game_training', 'tournament']);
    }

    public function down(): void
    {
        DB::table('events')->where('type', 'game_training')->update(['type' => 'training']);
        $this->replaceTypeConstraint(['game', 'training', 'tournament']);
    }

    /** @param list<string> $types */
    private function replaceTypeConstraint(array $types): void
    {
        $connection = DB::connection();

        if ($connection->getDriverName() === 'pgsql') {
            $allowed = implode(', ', array_map(
                fn (string $type): string => $connection->getPdo()->quote($type),
                $types,
            ));

            DB::statement('ALTER TABLE events DROP CONSTRAINT IF EXISTS events_type_check');
            DB::statement("ALTER TABLE events ADD CONSTRAINT events_type_check CHECK (type IN ({$allowed}))");
        }
    }
};
