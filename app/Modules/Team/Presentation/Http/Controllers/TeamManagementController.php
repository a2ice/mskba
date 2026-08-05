<?php

namespace App\Modules\Team\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Team\Application\Services\TeamManagementAccess;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Team\Domain\Enums\TeamLineupAssignmentEnum;
use App\Modules\Team\Domain\Enums\TeamMemberTypeEnum;
use App\Modules\Team\Domain\Enums\TeamPermissionEnum;
use App\Modules\Team\Domain\Enums\TeamSportTypeEnum;
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
        abort_if($actor === null || ! $access->canManage($item, $actor), 403);

        $activeMemberships = $item->memberships
            ->filter(fn ($membership) => $membership->contract?->status === ContractStatusEnum::ACTIVE
                && $membership->invitation_status === TeamInvitationStatusEnum::ACCEPTED)
            ->values();
        $players = $activeMemberships
            ->filter(fn ($membership) => $membership->hasSportRole(TeamMemberTypeEnum::PLAYER))
            ->sortBy('id')
            ->values();
        $startingLineups = $item->sportProfiles
            ->mapWithKeys(function ($profile) use ($players): array {
                $size = $profile->sport_type === TeamSportTypeEnum::STREETBALL ? 3 : 5;
                $assignments = $profile->lineupMembers->keyBy('contract_membership_id');
                $ordered = $players
                    ->sortBy(fn ($player) => sprintf(
                        '%d-%010d',
                        $assignments->get($player->id)?->position ?? 9999,
                        $player->id,
                    ))
                    ->values();
                $starters = $ordered
                    ->filter(fn ($player) => $assignments->get($player->id)?->assignment === TeamLineupAssignmentEnum::STARTER)
                    ->values();
                $reserves = $ordered
                    ->reject(fn ($player) => $starters->contains('id', $player->id))
                    ->values();

                return [$profile->sport_type->value => [
                    'label' => $profile->sport_type->label(),
                    'size' => $size,
                    'sport_type' => $profile->sport_type->value,
                    'starters' => $starters,
                    'reserves' => $reserves,
                ]];
            });

        return ThemeResolver::page('teams.management', [
            'team' => $item,
            'activeMemberships' => $activeMemberships,
            'startingLineups' => $startingLineups,
            'canManageRoster' => $access->allows($item, $actor, TeamPermissionEnum::MANAGE_ROSTER),
            'canInviteMembers' => $access->allows($item, $actor, TeamPermissionEnum::INVITE_MEMBERS),
            'canManageRoles' => $access->allows($item, $actor, TeamPermissionEnum::MANAGE_ROLES),
            'canManagePermissions' => $access->allows($item, $actor, TeamPermissionEnum::MANAGE_PERMISSIONS),
            'canRemoveMembers' => $access->allows($item, $actor, TeamPermissionEnum::REMOVE_MEMBERS),
            'currentUserId' => $actor->user_id,
            'currentUserIsAdmin' => $actor->user?->isAdmin() ?? false,
            'teamPermissions' => TeamPermissionEnum::cases(),
        ]);
    }
}
