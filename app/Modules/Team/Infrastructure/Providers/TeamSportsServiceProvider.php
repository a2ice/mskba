<?php

namespace App\Modules\Team\Infrastructure\Providers;

use App\Modules\Admin\Presentation\Http\Controllers\AdminTeamsController;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Team\Application\Services\TeamLineupResolver;
use App\Modules\Team\Application\Services\TeamManagementAccess;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Team\Domain\Enums\TeamJoinRequestStatusEnum;
use App\Modules\Team\Domain\Enums\TeamMemberTypeEnum;
use App\Modules\Team\Domain\Enums\TeamPermissionEnum;
use App\Modules\Team\Infrastructure\Http\Middleware\EnsureTeamMemberRemovalHierarchy;
use App\Modules\Team\Infrastructure\Http\Middleware\EnsureTeamUserContext;
use App\Modules\Team\Infrastructure\Observers\OwnerTeamMembershipObserver;
use App\Modules\Team\Presentation\Http\Controllers\TeamJoinRequestController;
use App\Modules\Team\Presentation\Http\Controllers\TeamManagementController;
use App\Modules\Team\Presentation\Http\Controllers\TeamMemberSportsController;
use App\Modules\Team\Presentation\Http\Controllers\TeamSettingsController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

final class TeamSportsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        ContractMembership::observe(OwnerTeamMembershipObserver::class);
        $this->app['router']->pushMiddlewareToGroup('web', EnsureTeamUserContext::class);
        $this->app['router']->pushMiddlewareToGroup('web', EnsureTeamMemberRemovalHierarchy::class);

        Route::middleware(['web', 'auth'])->group(function (): void {
            Route::get('/teams/{team}/management', TeamManagementController::class)
                ->name('teams.management');
            Route::put('/teams/{team}/members/{membership}/sports', TeamMemberSportsController::class)
                ->whereNumber('membership')
                ->name('teams.members.sports.update');
            Route::patch('/teams/{team}/settings/applications', [TeamSettingsController::class, 'updateApplications'])
                ->name('teams.settings.applications.update');
            Route::get('/teams/{team}/join-requests', [TeamJoinRequestController::class, 'index'])
                ->name('teams.join-requests.index');
            Route::post('/teams/{team}/join-requests', [TeamJoinRequestController::class, 'store'])
                ->middleware('throttle:5,1')
                ->name('teams.join-requests.store');
            Route::patch('/teams/{team}/join-requests/{joinRequest}', [TeamJoinRequestController::class, 'respond'])
                ->whereNumber('joinRequest')
                ->name('teams.join-requests.respond');
        });

        Route::middleware(['web', 'auth', 'can:access-admin-panel'])
            ->get('/admin/teams/{team}', [AdminTeamsController::class, 'show'])
            ->name('admin.teams.show')
            ->defaults('breadcrumb', 'Команда');

        View::composer('theme::pages.teams.show', function ($view): void {
            $data = $view->getData();
            $team = $data['team'] ?? null;
            $activeMemberships = $data['activeMemberships'] ?? collect();

            if ($team === null) {
                return;
            }

            $coaches = $activeMemberships
                ->filter(fn ($membership) => $membership->hasSportRole(TeamMemberTypeEnum::COACH))
                ->values();
            $managers = $activeMemberships
                ->filter(fn ($membership) => $membership->hasSportRole(TeamMemberTypeEnum::MANAGER))
                ->values();
            $players = $activeMemberships
                ->filter(fn ($membership) => $membership->hasSportRole(TeamMemberTypeEnum::PLAYER))
                ->sortBy('id')
                ->values();
            $startingLineups = app(TeamLineupResolver::class)->resolve($team->sportProfiles, $players);

            $actor = app(CurrentActorResolver::class)->resolveForRequest(request());
            $access = app(TeamManagementAccess::class);
            $identityIds = $actor?->user?->canonical()->identityIds() ?? [];
            $currentJoinRequest = $identityIds === []
                ? null
                : $team->joinRequests()->whereIn('user_id', $identityIds)->latest('id')->first();
            $isActiveMember = $identityIds !== [] && $team->memberships()
                ->whereIn('user_id', $identityIds)
                ->where('invitation_status', TeamInvitationStatusEnum::ACCEPTED->value)
                ->whereHas('contract', fn ($query) => $query->where('status', ContractStatusEnum::ACTIVE->value))
                ->exists();

            $view->with([
                'coaches' => $coaches,
                'managers' => $managers,
                'players' => $players,
                'startingLineups' => $startingLineups,
                'hasCompleteRoster' => $startingLineups->every('is_complete'),
                'canEditSettings' => $actor !== null && $access->allows($team, $actor, TeamPermissionEnum::EDIT_SETTINGS),
                'canManageJoinRequests' => $actor !== null && $access->allows($team, $actor, TeamPermissionEnum::MANAGE_JOIN_REQUESTS),
                'currentJoinRequest' => $currentJoinRequest,
                'isActiveTeamMember' => $isActiveMember,
                'canApplyToTeam' => auth()->check()
                    && ! $isActiveMember
                    && ($currentJoinRequest?->status !== TeamJoinRequestStatusEnum::BLOCKED),
            ]);
        });

        View::composer('theme::pages.teams.edit', function ($view): void {
            $team = $view->getData()['team'] ?? null;
            $actor = app(CurrentActorResolver::class)->resolveForRequest(request());
            if ($team === null || $actor === null) {
                return;
            }

            $access = app(TeamManagementAccess::class);
            $view->with([
                'canManageJoinRequests' => $access->allows($team, $actor, TeamPermissionEnum::MANAGE_JOIN_REQUESTS),
            ]);
        });
    }
}
