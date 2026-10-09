<?php

namespace App\Modules\Team\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Team\Domain\Enums\TeamJoinRequestStatusEnum;
use App\Modules\Team\Domain\Enums\TeamLineupAssignmentEnum;
use App\Modules\Team\Domain\Enums\TeamSportTypeEnum;
use App\Modules\Team\Domain\Enums\TeamStatusEnum;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Team\Domain\Models\TeamJoinRequest;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

final class AccountTeamsController extends Controller
{
    private const CREATION_LIMIT = 5;

    public function __invoke(Request $request): Response
    {
        $filters = $request->validate([
            'condition' => ['nullable', Rule::in(array_column(TeamInvitationStatusEnum::cases(), 'value'))],
            'status' => ['nullable', Rule::in(array_column(TeamStatusEnum::cases(), 'value'))],
            'created_only' => ['nullable', 'boolean'],
        ]);
        $user = $request->user()->canonical();
        $identityIds = $user->identityIds();
        $createdOnly = $request->boolean('created_only');
        $isApp = app(ThemeResolver::class)->active() === 'mskba_app';
        $acceptedMembership = fn ($query) => $query
            ->whereIn('user_id', $identityIds)
            ->where('invitation_status', TeamInvitationStatusEnum::ACCEPTED->value);
        $pendingMembership = fn ($query) => $query
            ->whereIn('user_id', $identityIds)
            ->where('scope_type', ContractMembershipScopeTypeEnum::TEAM->value)
            ->where('invitation_status', TeamInvitationStatusEnum::PENDING->value);
        // Keep pending invitations separate from "My teams" in the new theme.
        // The legacy account list is deliberately unchanged.
        $ownTeam = fn ($query) => $query->whereHas('createdByActor',
            fn ($actor) => $actor->whereIn('user_id', $identityIds))
            ->orWhereHas('memberships', $acceptedMembership);

        $condition = (string) ($filters['condition'] ?? '');
        $status = $request->exists('status')
            ? (string) ($filters['status'] ?? '')
            : TeamStatusEnum::ACTIVE->value;
        $createdTeamsCount = Team::query()
            ->whereNull('temporary_for_event_id')
            ->whereHas('createdByActor', fn ($actor) => $actor->whereIn('user_id', $identityIds))
            ->count();
        $canCreateTeam = $user->hasSystemRole(UserSystemRoleEnum::SUPERADMIN)
            || $createdTeamsCount < self::CREATION_LIMIT;

        $hasAnyTeams = Team::query()->whereNull('temporary_for_event_id')
            ->where(function ($query) use ($identityIds, $isApp, $ownTeam): void {
                if ($isApp) {
                    $ownTeam($query);

                    return;
                }
                $query->whereHas('createdByActor', fn ($actor) => $actor->whereIn('user_id', $identityIds))
                    ->orWhereHas('memberships', fn ($memberships) => $memberships->whereIn('user_id', $identityIds));
            })->exists();

        $invitations = $isApp
            ? Team::query()
                ->whereNull('temporary_for_event_id')
                ->whereHas('memberships', $pendingMembership)
                ->with([
                    'logo',
                    'memberships' => $pendingMembership,
                ])
                ->orderBy('name')
                ->paginate(10, ['*'], 'invitations_page')
                ->withQueryString()
            : null;

        // Applications sent by the user are not the same as invitations received
        // from teams. Show their review history without exposing other users' data.
        $applications = $isApp
            ? TeamJoinRequest::query()
                ->whereIn('user_id', $identityIds)
                ->whereHas('team', fn ($query) => $query->whereNull('temporary_for_event_id'))
                ->with(['team', 'hiringPosition'])
                ->orderByRaw('CASE status WHEN ? THEN 0 WHEN ? THEN 1 ELSE 2 END', [
                    TeamJoinRequestStatusEnum::PENDING->value,
                    TeamJoinRequestStatusEnum::BLOCKED->value,
                ])
                ->orderByDesc('updated_at')
                ->paginate(10, ['*'], 'applications_page')
                ->withQueryString()
            : null;

        $teams = Team::query()
            ->whereNull('temporary_for_event_id')
            ->when($isApp, fn ($query) => $query->where(fn ($query) => $ownTeam($query)))
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($createdOnly,
                fn ($query) => $query->whereHas('createdByActor', fn ($actor) => $actor->whereIn('user_id', $identityIds)),
                fn ($query) => $query->where(function ($query) use ($identityIds): void {
                    $query->whereHas('createdByActor', fn ($actor) => $actor->whereIn('user_id', $identityIds))
                        ->orWhereHas('memberships', fn ($memberships) => $memberships->whereIn('user_id', $identityIds));
                }),
            )
            ->when($condition !== '', fn ($query) => $query->whereHas('memberships', fn ($memberships) => $memberships
                ->whereIn('user_id', $identityIds)->where('invitation_status', $condition)))
            ->with([
                'logo', 'createdByActor', 'sportProfiles.lineupMembers.membership.contract',
                'memberships' => fn ($memberships) => $memberships
                    ->whereIn('user_id', $identityIds)
                    ->where('scope_type', ContractMembershipScopeTypeEnum::TEAM->value)
                    ->with('contract'),
            ])->orderBy('name')->paginate(20)->withQueryString();

        $teams->through(function (Team $team): Team {
            $team->setAttribute('roster_complete', $team->sportProfiles->every(function ($profile): bool {
                $required = $profile->sport_type === TeamSportTypeEnum::STREETBALL ? 3 : 5;

                return $profile->lineupMembers->filter(fn ($assignment) => $assignment->assignment === TeamLineupAssignmentEnum::STARTER
                    && $assignment->membership?->invitation_status === TeamInvitationStatusEnum::ACCEPTED
                    && $assignment->membership?->contract?->status === ContractStatusEnum::ACTIVE)->count() === $required;
            }));

            return $team;
        });

        return ThemeResolver::page('account.teams', [
            'teams' => $teams,
            'statuses' => TeamStatusEnum::cases(),
            'filters' => ['condition' => $condition, 'status' => $status, 'created_only' => $createdOnly],
            'canCreateTeam' => $canCreateTeam,
            'createdTeamsCount' => $createdTeamsCount,
            'teamCreationLimit' => self::CREATION_LIMIT,
            'hasAnyTeams' => $hasAnyTeams,
            'invitations' => $invitations,
            'applications' => $applications,
        ]);
    }
}
