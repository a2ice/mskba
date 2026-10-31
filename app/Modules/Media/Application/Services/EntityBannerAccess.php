<?php

namespace App\Modules\Media\Application\Services;

use App\Filament\Backoffice\Resources\BackofficeUsers\BackofficeUserResource;
use App\Filament\Backoffice\Resources\Leagues\LeagueResource;
use App\Filament\Backoffice\Resources\Seasons\SeasonResource;
use App\Filament\Backoffice\Resources\Teams\TeamResource;
use App\Filament\Backoffice\Resources\Tournaments\TournamentResource;
use App\Filament\Backoffice\Resources\Venues\VenueResource;
use App\Modules\Contract\Application\Services\EntityContractManager;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum as Scope;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Models\Profile;
use App\Modules\League\Domain\Enums\LeaguePermissionEnum;
use App\Modules\League\Domain\Enums\SeasonPermissionEnum;
use App\Modules\League\Domain\Models\League;
use App\Modules\League\Domain\Models\LeagueSeason;
use App\Modules\Team\Application\Services\TeamManagementAccess;
use App\Modules\Team\Domain\Enums\TeamPermissionEnum;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Tournament\Application\Services\TournamentAccess;
use App\Modules\Tournament\Domain\Enums\TournamentPermissionEnum;
use App\Modules\Tournament\Domain\Models\Tournament;
use App\Modules\Venue\Application\Services\VenueAccessResolver;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

final class EntityBannerAccess
{
    public function allows(Request $request, string $context, Model $entity): bool
    {
        $user = $request->user('web')?->canonical();
        if ($user === null || $user->isBlocked() || $user->trashed()) {
            return false;
        }

        if ($context === 'backoffice') {
            return match (true) {
                $entity instanceof League => LeagueResource::canEdit($entity),
                $entity instanceof LeagueSeason => SeasonResource::canEdit($entity),
                $entity instanceof Team => TeamResource::canEdit($entity),
                $entity instanceof Tournament => TournamentResource::canEdit($entity),
                $entity instanceof Venue => VenueResource::canEdit($entity),
                $entity instanceof Profile => BackofficeUserResource::canEdit($entity->user),
                default => false,
            };
        }

        if ($context !== 'account') {
            return false;
        }

        $actor = app(CurrentActorResolver::class)->resolveForRequest($request);

        return match (true) {
            $entity instanceof Profile => (int) $entity->user_id === (int) $user->id,
            $entity instanceof League => app(EntityContractManager::class)->allows(
                $user, Scope::LEAGUE, $entity->id, LeaguePermissionEnum::EDIT->value,
            ),
            $entity instanceof LeagueSeason => app(EntityContractManager::class)->allows(
                $user, Scope::SEASON, $entity->id, SeasonPermissionEnum::EDIT->value,
            ),
            $entity instanceof Team => $actor !== null
                && app(TeamManagementAccess::class)->allows($entity, $actor, TeamPermissionEnum::EDIT_SETTINGS),
            $entity instanceof Tournament => $actor !== null
                && app(TournamentAccess::class)->allows($entity, $actor, TournamentPermissionEnum::MANAGE_DESCRIPTION),
            $entity instanceof Venue => $actor !== null
                && ! $entity->hasPendingModerationRequest()
                && app(VenueAccessResolver::class)->canEdit($user, $entity, $actor),
            default => false,
        };
    }
}
