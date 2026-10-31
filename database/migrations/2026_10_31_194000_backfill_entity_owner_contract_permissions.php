<?php

use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum as Scope;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\League\Domain\Enums\LeaguePermissionEnum;
use App\Modules\League\Domain\Enums\SeasonPermissionEnum;
use App\Modules\Team\Domain\Enums\TeamPermissionEnum;
use App\Modules\Tournament\Domain\Enums\TournamentPermissionEnum;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Non-destructive, idempotent transition to explicit ownership contracts.
 * Historic records already had an owner/creator relationship. This migration
 * records it without removing old memberships or changing commercial claims.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('contract_memberships') || ! Schema::hasTable('leagues')) {
            return;
        }
        $leagueRights = array_column(LeaguePermissionEnum::cases(), 'value');
        $seasonRights = array_column(SeasonPermissionEnum::cases(), 'value');
        $teamRights = array_column(TeamPermissionEnum::cases(), 'value');
        $tournamentRights = array_column(TournamentPermissionEnum::cases(), 'value');

        DB::table('leagues')->orderBy('id')->chunkById(100, function ($leagues) use ($leagueRights): void {
            foreach ($leagues as $league) {
                $owner = $this->canonicalConfirmedUser((int) $league->owner_user_id);
                if ($owner === null) {
                    continue;
                }
                $this->ensure(Scope::LEAGUE->value, (int) $league->id, $owner,
                    'owner', 'Владелец лиги «'.$league->name.'»', $leagueRights);
            }
        });

        DB::table('league_seasons')->orderBy('id')->chunkById(100, function ($seasons) use ($seasonRights): void {
            foreach ($seasons as $season) {
                $league = DB::table('leagues')->where('id', $season->league_id)->first();
                $owner = $league === null ? null : $this->canonicalConfirmedUser((int) $league->owner_user_id);
                if ($owner === null) {
                    continue;
                }
                $this->ensure(Scope::SEASON->value, (int) $season->id, $owner,
                    'owner', 'Владелец сезона «'.$season->name.'»', $seasonRights);
            }
        });

        DB::table('teams')->orderBy('id')->chunkById(100, function ($teams) use ($teamRights): void {
            foreach ($teams as $team) {
                $owner = DB::table('contract_memberships as m')
                    ->join('contracts as c', 'c.id', '=', 'm.contract_id')
                    ->where('m.scope_type', Scope::TEAM->value)
                    ->where('m.scope_id', $team->id)
                    ->where('m.access_level', 'owner')
                    ->where('c.status', 'active')
                    ->value('m.user_id');
                $owner ??= $this->creatorUserId($team->created_by_actor_id);
                $canonical = $owner === null ? null : $this->canonicalConfirmedUser((int) $owner);
                if ($canonical === null) {
                    continue;
                }
                $this->ensure(Scope::TEAM->value, (int) $team->id, $canonical,
                    'owner', 'Владелец команды «'.$team->name.'»', $teamRights);
            }
        });

        DB::table('tournaments')->orderBy('id')->chunkById(100, function ($tournaments) use ($tournamentRights): void {
            foreach ($tournaments as $tournament) {
                $ownerId = $this->creatorUserId($tournament->created_by_actor_id);
                $owner = $ownerId === null ? null : $this->canonicalConfirmedUser($ownerId);
                if ($owner === null) {
                    continue;
                }
                $this->ensure(Scope::TOURNAMENT->value, (int) $tournament->id, $owner,
                    'owner', 'Владелец турнира «'.$tournament->title.'»', $tournamentRights);
            }
        });

        // A venue's primary manager is responsible for catalogue data, NOT
        // its verified commercial ownership. The latter is issued by claims.
        $catalogueRights = [
            VenuePermissionEnum::VIEW->value,
            VenuePermissionEnum::EDIT->value,
            VenuePermissionEnum::EDIT_SCHEDULE->value,
            VenuePermissionEnum::REMOVE->value,
            VenuePermissionEnum::MANAGE_CONTRACTS->value,
        ];
        DB::table('venues')->orderBy('id')->chunkById(100, function ($venues) use ($catalogueRights): void {
            foreach ($venues as $venue) {
                $verified = DB::table('contract_memberships as m')
                    ->join('contracts as c', 'c.id', '=', 'm.contract_id')
                    ->where('m.scope_type', Scope::VENUE->value)->where('m.scope_id', $venue->id)
                    ->where('m.access_level', 'owner')->where('c.status', 'active')->exists();
                if ($verified) {
                    continue;
                }
                $ownerId = $this->creatorUserId($venue->created_by_actor_id);
                $owner = $ownerId === null ? null : $this->canonicalConfirmedUser($ownerId);
                if ($owner !== null) {
                    $this->ensure(Scope::VENUE->value, (int) $venue->id, $owner,
                        'primary_manager', 'Ответственный за площадку «'.$venue->name.'»', $catalogueRights);
                }
            }
        });

        // Older verified venue owners must also be able to assign ordinary
        // public contracts, without altering their original commercial rights.
        $verifiedOwners = DB::table('contract_memberships as m')
            ->join('contracts as c', 'c.id', '=', 'm.contract_id')
            ->where('m.scope_type', Scope::VENUE->value)->where('m.access_level', 'owner')
            ->where('c.status', 'active')->pluck('m.contract_id');
        foreach ($verifiedOwners as $contractId) {
            $this->addMissingPermissions((int) $contractId, [VenuePermissionEnum::MANAGE_CONTRACTS->value]);
        }
    }

    private function canonicalConfirmedUser(int $id): ?int
    {
        $user = User::query()->find($id)?->canonical();
        return $user !== null && $user->status === UserStatusEnum::CONFIRMED
            && ! $user->isBlocked() && ! $user->trashed() ? $user->id : null;
    }

    private function creatorUserId(?int $actorId): ?int
    {
        if ($actorId === null) {
            return null;
        }
        $id = DB::table('actors')->where('id', $actorId)->value('user_id');
        return $id === null ? null : (int) $id;
    }

    /** @param list<string> $permissions */
    private function ensure(string $scope, int $scopeId, int $ownerId, string $role, string $name, array $permissions): void
    {
        DB::transaction(function () use ($scope, $scopeId, $ownerId, $role, $name, $permissions): void {
            $existing = DB::table('contract_memberships as m')
                ->join('contracts as c', 'c.id', '=', 'm.contract_id')
                ->where('m.scope_type', $scope)->where('m.scope_id', $scopeId)
                ->where('m.access_level', $role)->where('c.status', 'active')
                ->orderBy('m.id')->first(['m.user_id', 'm.contract_id']);
            if ($existing !== null) {
                // Never silently replace an existing owner or rewrite history.
                $this->addMissingPermissions((int) $existing->contract_id, $permissions);
                return;
            }
            $now = now();
            $id = DB::table('contracts')->insertGetId([
                'family' => 'membership',
                'name' => $name,
                'status' => 'active',
                'starts_at' => $now,
                'assigned_by' => $ownerId,
                'assigned_at' => $now,
                'assigner' => 'flow',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('contract_memberships')->insert([
                'contract_id' => $id,
                'scope_type' => $scope,
                'scope_id' => $scopeId,
                'user_id' => $ownerId,
                'access_level' => $role,
                'invitation_status' => 'accepted',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->addMissingPermissions((int) $id, $permissions);
        });
    }

    /** @param list<string> $permissions */
    private function addMissingPermissions(int $id, array $permissions): void
    {
        $existing = DB::table('contract_permissions')->where('contract_id', $id)->pluck('permission')->all();
        $missing = array_diff($permissions, $existing);
        if ($missing === []) {
            return;
        }
        $now = now();
        DB::table('contract_permissions')->insertOrIgnore(array_map(
            static fn (string $permission): array => [
                'contract_id' => $id, 'permission' => $permission,
                'created_at' => $now, 'updated_at' => $now,
            ], $missing,
        ));
    }

    public function down(): void
    {
        // Historical contracts, roles and permissions must not be deleted on rollback.
    }
};
