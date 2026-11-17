<?php

use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Preserve historical rental decision grants, while explicitly separating
 * notification/conversation, confirmation and rejection. Also repair
 * previously created primary_manager contracts that had only five catalogue
 * permissions (Basketpoint #26 is one example).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('contract_permissions') || ! Schema::hasTable('contract_memberships')) {
            return;
        }

        $scope = ContractMembershipScopeTypeEnum::VENUE->value;
        $owners = DB::table('contract_memberships')
            ->where('scope_type', $scope)
            ->whereIn('access_level', ['owner', 'primary_manager'])
            ->pluck('contract_id')->map(fn ($id): int => (int) $id)->all();

        $legacy = DB::table('contract_permissions')
            ->where('permission', 'rental.bookings.decide')
            ->whereIn('contract_id', DB::table('contract_memberships')
                ->whereIn('scope_type', [$scope, ContractMembershipScopeTypeEnum::VENUE_COURT->value])
                ->select('contract_id'))
            ->pluck('contract_id')->map(fn ($id): int => (int) $id)->all();

        $rights = ['rental.bookings.view', 'rental.bookings.confirm', 'rental.bookings.reject'];
        foreach (array_unique([...$owners, ...$legacy]) as $contractId) {
            $present = DB::table('contract_permissions')
                ->where('contract_id', $contractId)->pluck('permission')->all();
            $missing = array_diff($rights, $present);
            if ($missing === []) continue;
            $now = now();
            DB::table('contract_permissions')->insertOrIgnore(array_map(
                static fn (string $permission): array => [
                    'contract_id' => $contractId,
                    'permission' => $permission,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $missing,
            ));
        }
        // The combined right is no longer consulted anywhere in the app.
        // Remove it only after equivalent confirm/reject grants are saved.
        DB::table('contract_permissions')
            ->where('permission', 'rental.bookings.decide')
            ->whereIn('contract_id', $legacy)->delete();
    }

    public function down(): void
    {
        // Do not silently revoke confirmed venue access on rollback.
    }
};
