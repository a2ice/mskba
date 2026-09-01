<?php

namespace App\Modules\Team\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Team\Application\Services\TeamLineupResolver;
use App\Modules\Team\Application\Services\TeamManagementAccess;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Team\Domain\Enums\TeamMemberTypeEnum;
use App\Modules\Team\Domain\Enums\TeamPermissionEnum;
use App\Modules\Team\Domain\Models\Team;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class TeamManagementController extends Controller
{
    public function __invoke(
        string $team,
        Request $request,
        CurrentActorResolver $actors,
        TeamManagementAccess $access,
        TeamLineupResolver $lineups,
    ): Response {
        $item = Team::query()->whereRouteIdentifier($team)
            ->with([
                'logo',
                'sportProfiles.lineupMembers',
                'memberships.contract.permissions',
                'memberships.user.profile.activeAvatar',
            ])
            ->firstOrFail();

        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null || ! $access->canManageMembersAndRoster($item, $actor), 403);

        $activeMemberships = $item->memberships
            ->filter(fn ($membership) => $membership->contract?->status === ContractStatusEnum::ACTIVE
                && $membership->invitation_status === TeamInvitationStatusEnum::ACCEPTED)
            ->values();
        $pendingMemberships = $item->memberships
            ->filter(fn ($membership) => $membership->invitation_status === TeamInvitationStatusEnum::PENDING)
            ->sortByDesc('updated_at')
            ->values();
        $players = $activeMemberships
            ->filter(fn ($membership) => $membership->hasSportRole(TeamMemberTypeEnum::PLAYER))
            ->sortBy('id')
            ->values();
        $startingLineups = $lineups->resolve($item->sportProfiles, $players);

        return ThemeResolver::page('teams.management', [
            'team' => $item,
            'activeMemberships' => $activeMemberships,
            'pendingMemberships' => $pendingMemberships,
            'startingLineups' => $startingLineups,
            'canEditSettings' => $access->allows($item, $actor, TeamPermissionEnum::EDIT_SETTINGS),
            'canManageJoinRequests' => $access->allows($item, $actor, TeamPermissionEnum::MANAGE_JOIN_REQUESTS),
            'canManageRoster' => $access->allows($item, $actor, TeamPermissionEnum::MANAGE_ROSTER),
            'canInviteMembers' => $access->allows($item, $actor, TeamPermissionEnum::INVITE_MEMBERS),
            'canManageRoles' => $access->allows($item, $actor, TeamPermissionEnum::MANAGE_ROLES),
            'canManagePermissions' => $access->allows($item, $actor, TeamPermissionEnum::MANAGE_PERMISSIONS),
            'canRemoveMembers' => $access->allows($item, $actor, TeamPermissionEnum::REMOVE_MEMBERS),
            'canManageMembersAndRoster' => true,
            'canManageVenues' => $access->allows($item, $actor, TeamPermissionEnum::MANAGE_VENUES),
            'canManageHiring' => $access->allows($item, $actor, TeamPermissionEnum::MANAGE_HIRING),
            'currentUserId' => $actor->user_id,
            'teamPermissions' => TeamPermissionEnum::cases(),
        ]);
    }
}
