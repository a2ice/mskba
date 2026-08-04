<?php

use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Team\Domain\Enums\TeamLineupAssignmentEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_memberships', function (Blueprint $table): void {
            $table->string('invitation_status', 24)
                ->default(TeamInvitationStatusEnum::ACCEPTED->value)
                ->after('is_default_starter');
        });

        Schema::create('team_sport_lineup_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_sport_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_membership_id')->constrained()->cascadeOnDelete();
            $table->string('assignment', 20)->default(TeamLineupAssignmentEnum::RESERVE->value);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['team_sport_profile_id', 'contract_membership_id'], 'team_sport_lineup_member_unique');
            $table->index(['team_sport_profile_id', 'assignment', 'position'], 'team_sport_lineup_order');
        });

        $now = now();
        DB::table('team_sport_profiles')->orderBy('id')->each(function ($profile) use ($now): void {
            $memberships = DB::table('contract_memberships')
                ->join('contracts', 'contracts.id', '=', 'contract_memberships.contract_id')
                ->where('contract_memberships.scope_type', 'team')
                ->where('contract_memberships.scope_id', $profile->team_id)
                ->where('contracts.status', 'active')
                ->where(function ($query): void {
                    $query->whereNull('contract_memberships.member_type')
                        ->orWhere('contract_memberships.member_type', 'player');
                })
                ->orderByDesc('contract_memberships.is_default_starter')
                ->orderBy('contract_memberships.id')
                ->pluck('contract_memberships.id');
            $limit = $profile->sport_type === 'streetball' ? 3 : 5;
            foreach ($memberships as $position => $membershipId) {
                DB::table('team_sport_lineup_members')->insert([
                    'team_sport_profile_id' => $profile->id,
                    'contract_membership_id' => $membershipId,
                    'assignment' => $position < $limit ? 'starter' : 'reserve',
                    'position' => $position,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_sport_lineup_members');
        Schema::table('contract_memberships', fn (Blueprint $table) => $table->dropColumn('invitation_status'));
    }
};
