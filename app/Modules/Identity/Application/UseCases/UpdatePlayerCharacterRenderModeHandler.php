<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\Participation\PlayerProfile;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Support\PlayerCharacterAppearanceOptions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class UpdatePlayerCharacterRenderModeHandler
{
    /**
     * @throws AuthorizationException
     */
    public function handle(User $user, string $renderMode): PlayerProfile
    {
        if (! in_array($renderMode, PlayerCharacterAppearanceOptions::RENDER_MODES, true)) {
            throw new InvalidArgumentException('Неизвестный режим отображения персонажа.');
        }

        if (! $user->hasActiveRole(UserParticipationRoleEnum::PLAYER->value)) {
            throw new AuthorizationException('Профиль игрока доступен только пользователю с активной ролью «Игрок».');
        }

        if ($renderMode === '3d' && ! $user->system_role->atLeast(UserSystemRoleEnum::ADMIN)) {
            throw new AuthorizationException('3D-модель пока доступна только администраторам.');
        }

        return DB::transaction(function () use ($user, $renderMode): PlayerProfile {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $profile = $lockedUser->playerProfile()->firstOrCreate();
            $extra = is_array($profile->extra) ? $profile->extra : [];
            $character = is_array($extra['character'] ?? null) ? $extra['character'] : [];

            $character['render_mode'] = $renderMode;
            $extra['character'] = $character;

            $profile->forceFill(['extra' => $extra])->save();

            return $profile->refresh();
        });
    }
}
