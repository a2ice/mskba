<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // The previous ceiling made these rows ineffective. They must not
        // become grants just because the service-account ceiling expanded.
        DB::table('user_operational_permissions')
            ->whereIn('user_id', DB::table('users')->select('id')->where('system_role', 'system'))
            ->where('is_allowed', true)
            ->whereNotIn('permission', [
                'backoffice.system_access',
                'backoffice.page_builder.view',
                'backoffice.page_builder.edit',
                'backoffice.page_builder.publish',
                'backoffice.page_builder.css.view',
                'backoffice.page_builder.css.edit',
                'backoffice.page_builder.css.publish',
                'backoffice.page_builder.js.view',
                'backoffice.page_builder.js.edit',
                'backoffice.page_builder.js.publish',
            ])
            ->update(['is_allowed' => false, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Restoring a dormant grant would require a new explicit assignment.
    }
};
