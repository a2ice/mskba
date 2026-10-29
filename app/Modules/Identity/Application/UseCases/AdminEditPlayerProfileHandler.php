<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\Participation\PlayerBodyTypeEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AdminEditPlayerProfileHandler
{
    public const EDITABLE = ['height_cm', 'weight_kg', 'body_type', 'experience_started_year', 'comment'];

    /** @param array<string, mixed> $values */
    public function handle(User $actor, User $target, array $values, string $reason): AuditLog
    {
        $actor = $actor->canonical();
        $target = $target->canonical();
        app(AdminEditPersonalProfileHandler::class)->authorize($actor, $target);
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Укажите основание изменения (10–500 символов).']);
        }
        if ($values === [] || array_diff(array_keys($values), self::EDITABLE) !== []) {
            throw ValidationException::withMessages(['player' => 'Недопустимые поля игрового профиля.']);
        }

        $values = Validator::make($values, [
            'height_cm' => ['sometimes', 'nullable', 'integer', 'between:150,220'],
            'weight_kg' => ['sometimes', 'nullable', 'numeric', 'between:30,250'],
            'body_type' => ['sometimes', 'nullable', Rule::enum(PlayerBodyTypeEnum::class)],
            'experience_started_year' => ['sometimes', 'nullable', 'integer', 'between:1950,'.now()->year],
            'comment' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ])->validate();

        return DB::transaction(function () use ($actor, $target, $values, $reason): AuditLog {
            $target = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            app(AdminEditPersonalProfileHandler::class)->authorize($actor, $target);
            if (! $target->hasActiveRole(UserParticipationRoleEnum::PLAYER->value)) {
                throw ValidationException::withMessages(['player' => 'У пользователя нет активной роли игрока.']);
            }
            $profile = $target->playerProfile()->lockForUpdate()->first();
            $profile ??= $target->playerProfile()->create([]);

            $before = [];
            $after = [];
            foreach ($values as $key => $value) {
                $current = $profile->getRawOriginal($key);
                $normalized = $value === '' ? null : $value;
                if ($current === $normalized || ($current !== null && $normalized !== null && (string) $current === (string) $normalized)) {
                    continue;
                }
                $before[$key] = $current;
                $after[$key] = $normalized;
            }
            if ($after === []) {
                throw ValidationException::withMessages(['player' => 'Данные игрока не изменились.']);
            }
            $profile->fill($after)->save();
            $profile->refresh();
            foreach (array_keys($after) as $key) {
                $after[$key] = $profile->getRawOriginal($key);
            }

            return AuditLog::query()->create([
                'actor_id' => app(CurrentActorResolver::class)->resolveForRequest(request())?->id,
                'auditable_type' => User::class,
                'auditable_id' => $target->id,
                'event' => 'admin_player_profile_changed',
                'old_values' => $before,
                'new_values' => $after,
                'metadata' => ['reason' => $reason, 'player_profile_id' => $profile->id],
            ]);
        });
    }
}
