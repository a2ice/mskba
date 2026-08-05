<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_memberships', function (Blueprint $table): void {
            $table->jsonb('sport_roles')->nullable()->after('member_type');
        });

        DB::table('contract_memberships')
            ->orderBy('id')
            ->eachById(function ($membership): void {
                $role = $membership->member_type;
                if ($role === null) {
                    $role = match ($membership->access_level) {
                        'coach' => 'coach',
                        'responsible' => 'manager',
                        'owner', 'captain', 'player' => 'player',
                        default => null,
                    };
                }
                if ($role === null) {
                    return;
                }

                DB::table('contract_memberships')
                    ->where('id', $membership->id)
                    ->update([
                        'member_type' => $role,
                        'sport_roles' => json_encode([$role], JSON_THROW_ON_ERROR),
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('contract_memberships', function (Blueprint $table): void {
            $table->dropColumn('sport_roles');
        });
    }
};
