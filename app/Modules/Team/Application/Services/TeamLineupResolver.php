<?php

namespace App\Modules\Team\Application\Services;

use App\Modules\Team\Domain\Enums\TeamLineupAssignmentEnum;
use App\Modules\Team\Domain\Enums\TeamSportTypeEnum;
use Illuminate\Support\Collection;

final class TeamLineupResolver
{
    /**
     * @param  Collection<int, mixed>  $profiles
     * @param  Collection<int, mixed>  $players
     * @return Collection<string, array<string, mixed>>
     */
    public function resolve(Collection $profiles, Collection $players): Collection
    {
        return $profiles->mapWithKeys(function ($profile) use ($players): array {
            $size = $profile->sport_type === TeamSportTypeEnum::STREETBALL ? 3 : 5;
            $assignments = $profile->lineupMembers->keyBy('contract_membership_id');
            $ordered = $players
                ->sortBy(fn ($player) => sprintf(
                    '%d-%d-%010d',
                    $assignments->get($player->id)?->position ?? 9999,
                    $player->is_default_starter ? 0 : 1,
                    $player->id,
                ))
                ->values();

            $explicitStarters = $ordered
                ->filter(fn ($player) => $assignments->get($player->id)?->assignment === TeamLineupAssignmentEnum::STARTER)
                ->values();

            $defaultStarters = $ordered
                ->filter(fn ($player) => $player->is_default_starter
                    && ! $assignments->has($player->id)
                    && ! $explicitStarters->contains('id', $player->id))
                ->take(max(0, $size - $explicitStarters->count()))
                ->values();

            $starters = $explicitStarters
                ->concat($defaultStarters)
                ->take($size)
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
    }
}
