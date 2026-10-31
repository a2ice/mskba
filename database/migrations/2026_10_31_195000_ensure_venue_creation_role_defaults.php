<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system_role_permission_profiles')) {
            return;
        }
        DB::table('system_role_permission_profiles')->orderBy('role')->get()->each(function ($profile): void {
            if ($profile->role === 'system') {
                return;
            }
            $current = json_decode((string) $profile->permissions, true);
            if (! is_array($current) || in_array('venue.create', $current, true)) {
                return;
            }
            $current[] = 'venue.create';
            DB::table('system_role_permission_profiles')->where('role', $profile->role)
                ->update(['permissions' => json_encode(array_values(array_unique($current))), 'updated_at' => now()]);
        });
    }

    public function down(): void
    {
        // Production-safe rollback: never silently revoke user or role permissions.
    }
};
