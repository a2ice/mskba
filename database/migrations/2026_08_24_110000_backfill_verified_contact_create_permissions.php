<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $users = DB::table('users')
            ->select(['id', 'canonical_user_id'])
            ->get()
            ->keyBy('id');

        $verifiedIdentityIds = DB::table('contacts')
            ->where('contactable_type', 'user')
            ->whereNotNull('verified_at')
            ->whereNull('deleted_at')
            ->distinct()
            ->pluck('contactable_id')
            ->map(fn ($id): int => (int) $id);

        $canonicalIds = $verifiedIdentityIds
            ->map(function (int $userId) use ($users): int {
                $currentId = $userId;
                $visited = [];

                while (isset($users[$currentId]) && $users[$currentId]->canonical_user_id !== null) {
                    if (isset($visited[$currentId])) {
                        break;
                    }

                    $visited[$currentId] = true;
                    $currentId = (int) $users[$currentId]->canonical_user_id;
                }

                return $currentId;
            })
            ->filter(fn (int $userId): bool => isset($users[$userId]))
            ->unique()
            ->values();

        $now = now();
        $rows = [];
        foreach ($canonicalIds as $userId) {
            foreach (['event.create', 'tournament.create'] as $permission) {
                $rows[] = [
                    'user_id' => $userId,
                    'permission' => $permission,
                    'is_allowed' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('user_operational_permissions')->insertOrIgnore($chunk);
        }
    }

    public function down(): void
    {
        DB::table('user_operational_permissions')
            ->whereIn('permission', ['event.create', 'tournament.create'])
            ->delete();
    }
};
