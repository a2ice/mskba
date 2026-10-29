<?php

use App\Modules\Identity\Domain\Enums\Participation\PlayerBodyTypeEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->replaceAllowedBodyTypes(array_column(PlayerBodyTypeEnum::cases(), 'value'));
    }

    public function down(): void
    {
        // Do not silently erase a user's choice just to restore a legacy CHECK.
        if (DB::table('player_profiles')->where('body_type', PlayerBodyTypeEnum::NORMAL->value)->exists()) {
            throw new RuntimeException('Cannot roll back body_type normal while player profiles use it.');
        }

        $this->replaceAllowedBodyTypes(
            array_values(array_filter(
                array_column(PlayerBodyTypeEnum::cases(), 'value'),
                static fn (string $type): bool => $type !== PlayerBodyTypeEnum::NORMAL->value,
            )),
        );
    }

    /** @param list<string> $values */
    private function replaceAllowedBodyTypes(array $values): void
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        if (! in_array($driver, ['pgsql', 'mysql'], true)) {
            // Fresh SQLite test schemas already use the current PHP enum.
            return;
        }

        $quoted = implode(', ', array_map(
            static fn (string $value): string => $connection->getPdo()->quote($value),
            $values,
        ));

        if ($driver === 'pgsql') {
            // The 2026-07-29 migration created a PostgreSQL enum CHECK when
            // 'normal' did not exist. Fresh databases have the new value, but
            // already-deployed DBs keep the original constraint.
            DB::statement('ALTER TABLE player_profiles DROP CONSTRAINT IF EXISTS player_profiles_body_type_check');
            DB::statement("ALTER TABLE player_profiles ADD CONSTRAINT player_profiles_body_type_check CHECK (body_type IS NULL OR body_type IN ({$quoted}))");

            return;
        }

        DB::statement("ALTER TABLE player_profiles MODIFY body_type ENUM({$quoted}) NULL");
    }
};
