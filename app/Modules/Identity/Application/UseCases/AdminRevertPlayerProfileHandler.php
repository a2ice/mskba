<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AdminRevertPlayerProfileHandler
{
    public function handle(User $actor, User $target, int $revisionId, string $reason): AuditLog
    {
        $actor = $actor->canonical();
        $target = $target->canonical();
        app(AdminEditPersonalProfileHandler::class)->authorize($actor, $target);
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Укажите основание отката (10–500 символов).']);
        }

        return DB::transaction(function () use ($actor, $target, $revisionId, $reason): AuditLog {
            $target = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            app(AdminEditPersonalProfileHandler::class)->authorize($actor, $target);
            if (! $target->hasActiveRole(UserParticipationRoleEnum::PLAYER->value)) {
                throw ValidationException::withMessages(['player' => 'Роль игрока больше не активна.']);
            }
            $revision = AuditLog::query()
                ->whereKey($revisionId)
                ->where('auditable_type', User::class)
                ->where('auditable_id', $target->id)
                ->where('event', 'admin_player_profile_changed')
                ->lockForUpdate()->firstOrFail();

            $before = (array) $revision->old_values;
            $after = (array) $revision->new_values;
            if ($after === [] || array_keys($before) !== array_keys($after)
                || array_diff(array_keys($after), AdminEditPlayerProfileHandler::EDITABLE) !== []) {
                throw ValidationException::withMessages(['revision' => 'Ревизия не подходит для отката.']);
            }
            $profile = $target->playerProfile()->lockForUpdate()->firstOrFail();
            if ((int) ($revision->metadata['player_profile_id'] ?? 0) !== (int) $profile->id) {
                throw ValidationException::withMessages(['revision' => 'Профиль игрока был заменён.']);
            }
            foreach ($after as $key => $expected) {
                if ($profile->getRawOriginal($key) !== $expected) {
                    throw ValidationException::withMessages([
                        'revision' => 'После этой ревизии данные игрока уже менялись. Откат запрещён.',
                    ]);
                }
            }

            $profile->fill($before)->save();
            return AuditLog::query()->create([
                'actor_id' => app(CurrentActorResolver::class)->resolveForRequest(request())?->id,
                'auditable_type' => User::class,
                'auditable_id' => $target->id,
                'event' => 'admin_player_profile_reverted',
                'old_values' => $after,
                'new_values' => $before,
                'metadata' => [
                    'reason' => $reason,
                    'reverted_revision_id' => $revision->id,
                    'player_profile_id' => $profile->id,
                ],
            ]);
        });
    }
}
