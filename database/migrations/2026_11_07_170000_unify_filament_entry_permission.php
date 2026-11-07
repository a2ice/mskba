<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const COMMON = 'backoffice.access';

    private const LEGACY = 'backoffice.system_access';

    public function up(): void
    {
        $this->moveSystemGrants(self::LEGACY, self::COMMON);
        DB::table('user_operational_permissions')->where('permission', self::LEGACY)->delete();
    }

    public function down(): void
    {
        $this->moveSystemGrants(self::COMMON, self::LEGACY);
    }

    private function moveSystemGrants(string $from, string $to): void
    {
        DB::table('user_operational_permissions')
            ->whereIn('user_id', DB::table('users')->select('id')->where('system_role', 'system'))
            ->where('permission', $from)
            ->chunkById(200, function ($grants) use ($from, $to): void {
                $rows = $grants->map(fn ($grant): array => [
                    'user_id' => $grant->user_id,
                    'permission' => $to,
                    'is_allowed' => $grant->is_allowed,
                    'created_at' => $grant->created_at,
                    'updated_at' => $grant->updated_at,
                ])->all();

                DB::table('user_operational_permissions')
                    ->upsert($rows, ['user_id', 'permission'], ['is_allowed', 'updated_at']);
                DB::table('user_operational_permissions')
                    ->where('permission', $from)->whereIn('id', $grants->pluck('id'))->delete();
            });
    }
};
