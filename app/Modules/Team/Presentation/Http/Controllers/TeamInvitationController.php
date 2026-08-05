<?php

namespace App\Modules\Team\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contract\Domain\Enums\ContractFamilyEnum;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Enums\TeamMembershipAccessLevelEnum;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Application\Services\SearchDiscoverableUsers;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Application\Services\TeamManagementAccess;
use App\Modules\Team\Application\Services\TeamRosterService;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Team\Domain\Enums\TeamMemberTypeEnum;
use App\Modules\Team\Domain\Enums\TeamPermissionEnum;
use App\Modules\Team\Domain\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class TeamInvitationController extends Controller
{
    public function search(string $team, Request $request, CurrentActorResolver $actors, TeamManagementAccess $access, SearchDiscoverableUsers $users): JsonResponse
    {
        $item = Team::query()->whereRouteIdentifier($team)->firstOrFail();
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null || ! $access->allows($item, $actor, TeamPermissionEnum::INVITE_MEMBERS), 403);
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);
        $excluded = $item->memberships()
            ->whereIn('invitation_status', [
                TeamInvitationStatusEnum::PENDING->value,
                TeamInvitationStatusEnum::ACCEPTED->value,
            ])
            ->pluck('user_id')
            ->all();

        return response()->json(['users' => $users->handle($actor->user, $data['q'], $excluded)->map(fn (User $user) => [
            'id' => $user->id,
            'username' => $user->username,
            'name' => trim(($user->profile?->first_name ?? '').' '.($user->profile?->last_name ?? '')) ?: $user->username,
        ])->values()]);
    }

    public function store(string $team, Request $request, CurrentActorResolver $actors, TeamManagementAccess $access): JsonResponse
    {
        $item = Team::query()->whereRouteIdentifier($team)->firstOrFail();
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null || ! $access->allows($item, $actor, TeamPermissionEnum::INVITE_MEMBERS), 403);
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'member_type' => ['required', Rule::enum(TeamMemberTypeEnum::class)],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::enum(TeamPermissionEnum::class)],
        ]);
        abort_if(($data['permissions'] ?? []) !== []
            && ! $access->allows($item, $actor, TeamPermissionEnum::MANAGE_PERMISSIONS), 403);
        $memberType = TeamMemberTypeEnum::from($data['member_type']);
        $accessLevel = match ($memberType) {
            TeamMemberTypeEnum::PLAYER => TeamMembershipAccessLevelEnum::PLAYER,
            TeamMemberTypeEnum::COACH => TeamMembershipAccessLevelEnum::COACH,
            TeamMemberTypeEnum::MANAGER => TeamMembershipAccessLevelEnum::RESPONSIBLE,
        };

        $membership = DB::transaction(function () use ($item, $data, $actor, $memberType, $accessLevel): ContractMembership {
            $existing = $item->memberships()->where('user_id', $data['user_id'])->lockForUpdate()->first();
            $contract = $existing?->contract;
            if ($contract === null) {
                $contract = Contract::create([
                    'family' => ContractFamilyEnum::MEMBERSHIP,
                    'name' => "Приглашение в команду «{$item->name}»",
                    'status' => ContractStatusEnum::INACTIVE,
                    'assigned_by' => $actor->user_id,
                    'assigned_at' => now(),
                    'assigner' => UserParticipationRoleAssignerEnum::USER,
                ]);
                $existing = $contract->membership()->create([
                    'scope_type' => ContractMembershipScopeTypeEnum::TEAM,
                    'scope_id' => $item->id,
                    'user_id' => $data['user_id'],
                    'access_level' => $accessLevel,
                    'member_type' => $memberType,
                    'sport_roles' => [$memberType->value],
                    'invitation_status' => TeamInvitationStatusEnum::PENDING,
                ]);
            }
            $existing->update([
                'access_level' => $accessLevel,
                'member_type' => $memberType,
                'sport_roles' => [$memberType->value],
                'invitation_status' => TeamInvitationStatusEnum::PENDING,
                'is_captain' => false,
            ]);
            $contract->update(['status' => ContractStatusEnum::INACTIVE, 'assigned_by' => $actor->user_id, 'assigned_at' => now()]);
            $contract->permissions()->delete();
            $contract->permissions()->createMany(array_map(
                fn (string $permission) => ['permission' => $permission],
                $data['permissions'] ?? [],
            ));

            return $existing->fresh();
        });

        $membership->load(['contract.permissions', 'user.profile.activeAvatar']);

        return response()->json([
            'message' => 'Приглашение отправлено.',
            'invitation' => [
                'id' => $membership->id,
                'html' => view('theme::pages.teams.partials.pending-invitation', [
                    'invitation' => $membership,
                ])->render(),
            ],
        ], 201);
    }

    public function respond(
        int $membership,
        Request $request,
        TeamRosterService $rosters,
        CurrentActorResolver $actors,
        TeamManagementAccess $access,
    ): JsonResponse|RedirectResponse {
        $data = $request->validate(['decision' => ['required', Rule::in(['accept', 'decline', 'revoke'])]]);
        $user = $request->user();
        abort_if($user->isBlocked() || $user->trashed(), 403);

        $member = ContractMembership::query()
            ->with(['contract', 'sportLineupAssignments'])
            ->whereKey($membership)
            ->where('scope_type', ContractMembershipScopeTypeEnum::TEAM->value)
            ->firstOrFail();
        $team = Team::query()->with('sportProfiles.lineupMembers')->findOrFail($member->scope_id);

        if ($data['decision'] === 'revoke') {
            $actor = $actors->resolveForRequest($request);
            abort_if($actor === null || ! $access->allows($team, $actor, TeamPermissionEnum::INVITE_MEMBERS), 403);

            if ($member->invitation_status !== TeamInvitationStatusEnum::PENDING) {
                return response()->json(['message' => 'Отозвать можно только ожидающее приглашение.'], 422);
            }

            DB::transaction(function () use ($member): void {
                $member->contract->update(['status' => ContractStatusEnum::INACTIVE]);
                $member->update(['invitation_status' => TeamInvitationStatusEnum::REVOKED]);
            });

            return response()->json([
                'message' => 'Приглашение отозвано.',
                'membership_id' => $member->id,
            ]);
        }

        abort_if($member->user_id !== $user->id, 403);

        if ($member->invitation_status === TeamInvitationStatusEnum::REVOKED) {
            return back()->with('error', 'Приглашение было отозвано.');
        }

        if ($member->invitation_status !== TeamInvitationStatusEnum::PENDING) {
            return back()->with('error', 'Приглашение уже обработано.');
        }

        DB::transaction(function () use ($member, $team, $data, $rosters): void {
            $accepted = $data['decision'] === 'accept';
            $member->contract->update(['status' => $accepted ? ContractStatusEnum::ACTIVE : ContractStatusEnum::INACTIVE]);
            $member->update(['invitation_status' => $accepted ? TeamInvitationStatusEnum::ACCEPTED : TeamInvitationStatusEnum::DECLINED]);
            if ($accepted && $member->isPlayingMember()) {
                $rosters->synchronizePlayer($team, $member->id);
            }
        });

        return back()->with('status', $data['decision'] === 'accept' ? 'Приглашение принято.' : 'Приглашение отклонено.');
    }
}
