<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('contract_memberships', 'member_type')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        match ($driver) {
            'pgsql' => DB::statement(<<<'SQL'
                UPDATE contract_memberships
                SET sport_roles = jsonb_build_array(member_type)
                WHERE member_type IS NOT NULL
                  AND sport_roles IS NULL
            SQL),
            'sqlite' => DB::statement(<<<'SQL'
                UPDATE contract_memberships
                SET sport_roles = json_array(member_type)
                WHERE member_type IS NOT NULL
                  AND sport_roles IS NULL
            SQL),
            'mysql', 'mariadb' => DB::statement(<<<'SQL'
                UPDATE contract_memberships
                SET sport_roles = JSON_ARRAY(member_type)
                WHERE member_type IS NOT NULL
                  AND sport_roles IS NULL
            SQL),
            default => throw new RuntimeException("Unsupported database driver: {$driver}"),
        };

        Schema::table('contract_memberships', function (Blueprint $table): void {
            $table->dropColumn('member_type');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('contract_memberships', 'member_type')) {
            return;
        }

        Schema::table('contract_memberships', function (Blueprint $table): void {
            $table->string('member_type')->nullable()->after('access_level');
        });

        $driver = DB::connection()->getDriverName();

        match ($driver) {
            'pgsql' => DB::statement(<<<'SQL'
                UPDATE contract_memberships
                SET member_type = sport_roles->>0
                WHERE jsonb_array_length(COALESCE(sport_roles, '[]'::jsonb)) > 0
            SQL),
            'sqlite' => DB::statement(<<<'SQL'
                UPDATE contract_memberships
                SET member_type = json_extract(sport_roles, '$[0]')
                WHERE json_array_length(COALESCE(sport_roles, '[]')) > 0
            SQL),
            'mysql', 'mariadb' => DB::statement(<<<'SQL'
                UPDATE contract_memberships
                SET member_type = JSON_UNQUOTE(JSON_EXTRACT(sport_roles, '$[0]'))
                WHERE JSON_LENGTH(sport_roles) > 0
            SQL),
            default => throw new RuntimeException("Unsupported database driver: {$driver}"),
        };
    }
};
