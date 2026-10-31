<?php

namespace App\Modules\Media\Application\Services;

use App\Modules\Identity\Domain\Models\Profile;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\League\Domain\Models\League;
use App\Modules\League\Domain\Models\LeagueSeason;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Tournament\Domain\Models\Tournament;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class EntityBannerTargets
{
    public function get(string $type, int $id): Model
    {
        return match ($type) {
            'league' => League::query()->findOrFail($id),
            'season' => LeagueSeason::query()->findOrFail($id),
            'tournament' => Tournament::query()->findOrFail($id),
            'team' => Team::query()->findOrFail($id),
            'venue' => Venue::query()->findOrFail($id),
            // A single personal banner is shared by all roles of this account.
            'user' => User::query()->whereNull('canonical_user_id')->findOrFail($id)->profile()->firstOrFail(),
            default => abort(404),
        };
    }

    public function type(Model $entity): string
    {
        return match (true) {
            $entity instanceof League => 'league',
            $entity instanceof LeagueSeason => 'season',
            $entity instanceof Tournament => 'tournament',
            $entity instanceof Team => 'team',
            $entity instanceof Venue => 'venue',
            $entity instanceof Profile => 'user',
            default => throw new InvalidArgumentException('Объект не поддерживает баннеры.'),
        };
    }

    public function id(Model $entity): int
    {
        return $entity instanceof Profile ? (int) $entity->user_id : (int) $entity->getKey();
    }
}
