<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Application\Services\GlobalOperationalPermissionManager;
use App\Modules\Identity\Application\Services\SuperadminSelfEditAccess;
use App\Modules\Identity\Application\Services\UserOperationalPermissionChecker;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use App\Modules\Identity\Domain\Enums\UserGenderEnum;

final class AdminEditPersonalProfileHandler
{
    public const EDITABLE = ['first_name', 'last_name', 'middle_name', 'birth_date', 'gender'];

    /** @param array<string, string|null> $values */
    public function handle(User $actor, User $target, array $values, string $reason): AuditLog
    {
        $actor = $actor->canonical();
        $target = $target->canonical();
        $this->authorize($actor, $target);
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Укажите основание изменения (от 10 до 500 символов).']);
        }
        if ($values === [] || array_diff(array_keys($values), self::EDITABLE) !== []) {
            throw ValidationException::withMessages(['profile' => 'Недопустимые поля профиля.']);
        }
        Validator::make($values, [
            'first_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'middle_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'birth_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:1900-01-01'],
            'gender' => ['sometimes', 'nullable', Rule::enum(UserGenderEnum::class)],
        ])->validate();

        return DB::transaction(function () use ($actor, $target, $values, $reason): AuditLog {
            $target = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $target);
            $profile = $target->profile()->lockForUpdate()->first();
            $profile ??= $target->profile()->create([]);

            $before = [];
            $after = [];
            foreach ($values as $key => $value) {
                $current = $profile->getRawOriginal($key);
                $normalized = $value === '' ? null : $value;
                if ($current === $normalized) {
                    continue;
                }
                $before[$key] = $current;
                $after[$key] = $normalized;
            }
            if ($after === []) {
                throw ValidationException::withMessages(['profile' => 'Данные пользователя не изменились.']);
            }

            $profile->fill($after)->save();
            $profile->refresh();
            // Persist database-normalized values, not uncast form strings.
            // Rollback compares them with raw persisted state under a lock.
            foreach (array_keys($after) as $key) {
                $after[$key] = $profile->getRawOriginal($key);
            }

            return AuditLog::query()->create([
                'actor_id' => app(CurrentActorResolver::class)->resolveForRequest(request())?->id,
                'auditable_type' => User::class,
                'auditable_id' => $target->id,
                'event' => 'admin_personal_profile_changed',
                'old_values' => $before,
                'new_values' => $after,
                'metadata' => ['reason' => $reason, 'profile_id' => $profile->id],
            ]);
        });
    }

    public function authorize(User $actor, User $target): void
    {
        if (! SuperadminSelfEditAccess::allows($actor, $target)
            && (! app(GlobalOperationalPermissionManager::class)->mayManage($actor, $target)
                || ! app(UserOperationalPermissionChecker::class)->allows($actor, Permission::EDIT_USER_PERSONAL_DATA))) {
            throw new AuthorizationException('У вас нет права для редактирования пользователя.');
        }
    }
}
