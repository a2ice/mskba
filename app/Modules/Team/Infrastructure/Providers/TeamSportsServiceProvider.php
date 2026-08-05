<?php

namespace App\Modules\Team\Infrastructure\Providers;

use App\Modules\Admin\Presentation\Http\Controllers\AdminTeamsController;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Team\Domain\Enums\TeamLineupAssignmentEnum;
use App\Modules\Team\Domain\Enums\TeamMemberTypeEnum;
use App\Modules\Team\Domain\Enums\TeamSportTypeEnum;
use App\Modules\Team\Infrastructure\Http\Middleware\EnsureTeamMemberRemovalHierarchy;
use App\Modules\Team\Infrastructure\Http\Middleware\EnsureTeamUserContext;
use App\Modules\Team\Infrastructure\Observers\OwnerTeamMembershipObserver;
use App\Modules\Team\Presentation\Http\Controllers\TeamManagementController;
use App\Modules\Team\Presentation\Http\Controllers\TeamMemberSportsController;
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
            $startingLineups = $team->sportProfiles
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
                        'is_complete' => $players->count() >= $size && $starters->count() === $size,
                    ]];
                });

            $view->with([
                'coaches' => $coaches,
                'managers' => $managers,
                'players' => $players,
                'startingLineups' => $startingLineups,
                'hasCompleteRoster' => $startingLineups->every('is_complete'),
            ]);
        });
    }
}
