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
                if ($role === null && $membership->access_level === 'coach') {
                    $role = 'coach';
                }
                if ($role === null) {
                    return;
                }

                DB::table('contract_memberships')
                    ->where('id', $membership->id)
                    ->update(['sport_roles' => json_encode([$role], JSON_THROW_ON_ERROR)]);
            });
    }

    public function down(): void
    {
        Schema::table('contract_memberships', function (Blueprint $table): void {
            $table->dropColumn('sport_roles');
        });
    }
};
