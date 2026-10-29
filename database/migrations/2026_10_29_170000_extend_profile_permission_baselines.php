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

        // Preserve every administrator's existing template/custom grants and
        // add only the two permissions explicitly approved for admin baseline.
        $admin = DB::table('system_role_permission_profiles')->where('role', 'admin')->first();
        if ($admin !== null) {
            $permissions = json_decode((string) $admin->permissions, true) ?: [];
            $permissions = array_values(array_unique(array_merge($permissions, [
                'identity.profile.self_edit',
                'identity.profile_change.review',
            ])));
            DB::table('system_role_permission_profiles')->where('role', 'admin')
                ->update(['permissions' => json_encode($permissions), 'updated_at' => now()]);
        }

        // An admin who first becomes a reviewer must not gain retrospective
        // access to old private review threads by default.
        if (Schema::hasTable('review_permission_cursors')) {
            $lastRequestId = Schema::hasTable('profile_change_requests')
                ? ((int) (DB::table('profile_change_requests')->max('id') ?? 0)) : 0;
            DB::table('users')->where('system_role', 'admin')->select('id')
                ->orderBy('id')->chunkById(100, function ($admins) use ($lastRequestId): void {
                    foreach ($admins as $adminUser) {
                        DB::table('review_permission_cursors')->insertOrIgnore([
                            'user_id' => $adminUser->id,
                            'permission' => 'identity.profile_change.review',
                            'after_request_id' => $lastRequestId,
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                });
        }
    }

    public function down(): void
    {
        // Do not silently remove manually changed role baselines or reveal old
        // review threads by deleting cursor boundaries. Reversal requires an
        // explicit reviewed migration of the current live role profile.
    }
};
