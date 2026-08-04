<?php

namespace App\Modules\Team\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Team\Domain\Enums\TeamLineupAssignmentEnum;
use App\Modules\Team\Domain\Enums\TeamSportTypeEnum;
use App\Modules\Team\Domain\Models\Team;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

final class AccountTeamsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $filters = $request->validate([
            'condition' => ['nullable', Rule::in(array_column(TeamInvitationStatusEnum::cases(), 'value'))],
            'created_only' => ['nullable', 'boolean'],
        ]);
        $user = $request->user();
        $createdOnly = $request->boolean('created_only');
        $condition = (string) ($filters['condition'] ?? '');
        $teams = Team::query()
            ->whereNull('temporary_for_event_id')
            ->when($createdOnly,
                fn ($query) => $query->whereHas('createdByActor', fn ($actor) => $actor->where('user_id', $user->id)),
                fn ($query) => $query->where(function ($query) use ($user): void {
                    $query->whereHas('createdByActor', fn ($actor) => $actor->where('user_id', $user->id))
                        ->orWhereHas('memberships', fn ($memberships) => $memberships->where('user_id', $user->id));
                }),
            )
            ->when($condition !== '', fn ($query) => $query->whereHas('memberships', fn ($memberships) => $memberships
                ->where('user_id', $user->id)->where('invitation_status', $condition)))
            ->with([
                'logo', 'createdByActor', 'sportProfiles.lineupMembers.membership.contract',
                'memberships' => fn ($memberships) => $memberships
                    ->where('user_id', $user->id)
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
            'filters' => ['condition' => $condition, 'created_only' => $createdOnly],
        ]);
    }
}
