<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AdminRevertPersonalProfileHandler
{
    public function __construct(private readonly AdminEditPersonalProfileHandler $editor) {}

    public function handle(User $actor, User $target, int $revisionId, string $reason): AuditLog
    {
        $actor = $actor->canonical();
        $target = $target->canonical();
        $this->editor->authorize($actor, $target);
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Укажите основание отката (от 10 до 500 символов).']);
        }

        return DB::transaction(function () use ($actor, $target, $revisionId, $reason): AuditLog {
            $target = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            $this->editor->authorize($actor, $target);
            $revision = AuditLog::query()
                ->whereKey($revisionId)
                ->where('auditable_type', User::class)
                ->where('auditable_id', $target->id)
                ->where('event', 'admin_personal_profile_changed')
                ->lockForUpdate()
                ->firstOrFail();

            $before = (array) $revision->old_values;
            $after = (array) $revision->new_values;
            if ($after === [] || array_keys($before) !== array_keys($after)
                || array_diff(array_keys($after), AdminEditPersonalProfileHandler::EDITABLE) !== []) {
                throw ValidationException::withMessages(['revision' => 'Ревизия не подходит для автоматического отката.']);
            }
            $profile = $target->profile()->lockForUpdate()->firstOrFail();
            foreach ($after as $key => $expected) {
                if ($profile->getRawOriginal($key) !== $expected) {
                    throw ValidationException::withMessages([
                        'revision' => 'Данные изменились после этой ревизии. Автоматический откат запрещён.',
                    ]);
                }
            }

            $profile->fill($before)->save();
            return AuditLog::query()->create([
                'actor_id' => app(CurrentActorResolver::class)->resolveForRequest(request())?->id,
                'auditable_type' => User::class,
                'auditable_id' => $target->id,
                'event' => 'admin_personal_profile_reverted',
                'old_values' => $after,
                'new_values' => $before,
                'metadata' => [
                    'reason' => $reason,
                    'reverted_revision_id' => $revision->id,
                    'profile_id' => $profile->id,
                ],
            ]);
        });
    }
}
