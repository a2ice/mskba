<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Identity\Domain\Enums\Participation\PlayerPositionEnum;
use App\Modules\Identity\Application\Services\UserOperationalPermissionChecker;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Models\Participation\PlayerProfile;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class UpdatePlayerProfileHandler
{
    /**
     * @param  array<string, mixed>  $profileData
     * @param  array<int, PlayerPositionEnum>|null  $positions
     * @param  array<string, int|null>|null  $selfAssessment
     * @param  array<string, mixed>|null  $characterAppearance
     *
     * @throws AuthorizationException
     */
    public function handle(
        User $user,
        array $profileData,
        ?array $positions,
        ?array $selfAssessment,
        ?array $characterAppearance = null,
    ): PlayerProfile {
        return DB::transaction(function () use ($user, $profileData, $positions, $selfAssessment, $characterAppearance): PlayerProfile {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $role = $lockedUser->participationRoles(false)
                ->where('role', UserParticipationRoleEnum::PLAYER->value)
                ->lockForUpdate()->first();
            if ($role === null || $role->status !== UserParticipationRoleStatusEnum::ACTIVE) {
                throw new AuthorizationException('Профиль игрока доступен только пользователю с активной ролью «Игрок».');
            }

            $existing = $lockedUser->playerProfile()->lockForUpdate()->first();
            if ($role->confirmed_at !== null && ! app(UserOperationalPermissionChecker::class)
                ->allows($lockedUser, UserOperationalPermissionEnum::EDIT_OWN_PROFILE)) {
                foreach (['height_cm', 'experience_started_year'] as $key) {
                    if (array_key_exists($key, $profileData)
                        && (string) ($profileData[$key] ?? '') !== (string) ($existing?->{$key} ?? '')) {
                        throw new AuthorizationException('Для изменения подтверждённых данных игрока отправь заявку.');
                    }
                }
            }

            $profile = $lockedUser->playerProfile()->updateOrCreate([], $profileData);

            if ($characterAppearance !== null) {
                $extra = is_array($profile->extra) ? $profile->extra : [];
                $storedCharacter = is_array($extra['character'] ?? null) ? $extra['character'] : [];
                $extra['character'] = array_merge($storedCharacter, $characterAppearance);
                $profile->forceFill(['extra' => $extra])->save();
            }

            if ($positions !== null) {
                $profile->positions()->delete();
                $profile->positions()->createMany(
                    collect($positions)
                        ->map(fn (PlayerPositionEnum $position): array => ['position' => $position])
                        ->all(),
                );
            }

            if ($selfAssessment !== null) {
                $profile->selfAssessment()->updateOrCreate([], $selfAssessment);
            }

            return $profile->refresh()->load('positions', 'selfAssessment');
        });
    }
}
