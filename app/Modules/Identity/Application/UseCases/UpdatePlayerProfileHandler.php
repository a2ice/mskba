<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Identity\Domain\Enums\Participation\PlayerPositionEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Models\Participation\PlayerProfile;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class UpdatePlayerProfileHandler
{
    /**
     * @param  array<string, mixed>  $profileData
     * @param  array<int, PlayerPositionEnum>  $positions
     * @param  array<string, int|null>  $selfAssessment
     *
     * @throws AuthorizationException
     */
    public function handle(
        User $user,
        array $profileData,
        array $positions,
        array $selfAssessment,
    ): PlayerProfile {
        return DB::transaction(function () use ($user, $profileData, $positions, $selfAssessment): PlayerProfile {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            if (! $lockedUser->hasActiveRole(UserParticipationRoleEnum::PLAYER->value)) {
                throw new AuthorizationException('Профиль игрока доступен только пользователю с активной ролью «Игрок».');
            }

            $profile = $lockedUser->playerProfile()->updateOrCreate([], $profileData);

            $profile->positions()->delete();
            $profile->positions()->createMany(
                collect($positions)
                    ->map(fn (PlayerPositionEnum $position): array => ['position' => $position])
                    ->all(),
            );

            $profile->selfAssessment()->updateOrCreate([], $selfAssessment);

            return $profile->refresh()->load('positions', 'selfAssessment');
        });
    }
}
