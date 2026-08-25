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
     * @param  array<string, mixed>|null  $characterAppearance
     *
     * @throws AuthorizationException
     */
    public function handle(
        User $user,
        array $profileData,
        array $positions,
        array $selfAssessment,
        ?array $characterAppearance = null,
        ?string $characterFacePhotoPath = null,
    ): PlayerProfile {
        return DB::transaction(function () use (
            $user,
            $profileData,
            $positions,
            $selfAssessment,
            $characterAppearance,
            $characterFacePhotoPath,
        ): PlayerProfile {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            if (! $lockedUser->hasActiveRole(UserParticipationRoleEnum::PLAYER->value)) {
                throw new AuthorizationException('Профиль игрока доступен только пользователю с активной ролью «Игрок».');
            }

            $profile = $lockedUser->playerProfile()->updateOrCreate([], $profileData);

            if ($characterAppearance !== null || $characterFacePhotoPath !== null) {
                $extra = $profile->extra ?? [];
                $storedCharacter = is_array($extra['character'] ?? null) ? $extra['character'] : [];
                $nextCharacter = $characterAppearance !== null
                    ? array_merge($storedCharacter, $characterAppearance)
                    : $storedCharacter;

                if ($characterFacePhotoPath !== null) {
                    $nextCharacter['face_photo_path'] = $characterFacePhotoPath;
                }

                $extra['character'] = $nextCharacter;
                $profile->forceFill(['extra' => $extra])->save();
            }

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
