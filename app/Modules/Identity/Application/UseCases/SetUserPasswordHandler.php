<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\ValueObjects\PasswordVO;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SetUserPasswordHandler
{
    public function handle(User $user, ?string $currentPassword, string $newPassword): void
    {
        $this->change($user, $currentPassword, $newPassword, false, false);
    }

    public function handleAdministrator(User $actor, User $user, string $newPassword): void
    {
        $actor = $actor->canonical();

        if (! $actor->isConfirmed() || ! $actor->hasSystemRole(UserSystemRoleEnum::SUPERADMIN)) {
            throw new AuthorizationException('Устанавливать пароль пользователю может только подтверждённый superadmin.');
        }

        $this->change($user, null, $newPassword, true, true);
    }

    /**
     * Called only by verified-contact recovery. Delivery executes before the
     * transaction commits, so an undelivered password never replaces the old one.
     */
    public function handleRecovery(User $user, string $newPassword, Closure $deliver): void
    {
        $this->change($user, null, $newPassword, true, true, $deliver);
    }

    private function change(
        User $user,
        ?string $currentPassword,
        string $newPassword,
        bool $overrideCurrent,
        bool $temporary,
        ?Closure $deliver = null,
    ): void {
        $password = PasswordVO::fromString($newPassword)->value;

        DB::transaction(function () use ($user, $currentPassword, $password, $overrideCurrent, $temporary, $deliver): void {
            $requested = User::query()->whereKey($user->getKey())->firstOrFail();
            $canonicalId = (int) $requested->canonical()->id;
            $identityUsers = User::query()
                ->where(function ($query) use ($canonicalId): void {
                    $query
                        ->whereKey($canonicalId)
                        ->orWhere('canonical_user_id', $canonicalId);
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ((int) $requested->refresh()->canonical()->id !== $canonicalId) {
                throw ValidationException::withMessages([
                    'current_password' => 'Аккаунты были объединены параллельно. Повторите смену пароля.',
                ]);
            }

            /** @var User|null $canonical */
            $canonical = $identityUsers->firstWhere('id', $canonicalId);
            if ($canonical === null) {
                throw ValidationException::withMessages([
                    'current_password' => 'Не удалось определить основной аккаунт. Повторите попытку.',
                ]);
            }

            if ($canonical->isBlocked() || $canonical->trashed()) {
                throw new AuthorizationException('Пароль заблокированного аккаунта изменять нельзя.');
            }

            if (! $overrideCurrent && $canonical->password !== null) {
                $currentPasswordMatches = $currentPassword !== null
                    && $identityUsers->contains(
                        fn (User $identityUser): bool => $identityUser->password !== null
                            && Hash::check($currentPassword, $identityUser->password),
                    );

                if (! $currentPasswordMatches) {
                    throw ValidationException::withMessages([
                        'current_password' => 'Текущий пароль указан неверно.',
                    ]);
                }
            }

            $changedAt = now();

            foreach ($identityUsers as $identityUser) {
                if ((int) $identityUser->id === $canonicalId) {
                    $identityUser->forceFill([
                        'password' => $password,
                        'password_updated_at' => $changedAt,
                        'is_temporary_password' => $temporary,
                        'remember_token' => Str::random(60),
                    ])->save();

                    continue;
                }

                $identityUser->forceFill([
                    'password' => null,
                    'password_updated_at' => $changedAt,
                    'is_temporary_password' => false,
                    'remember_token' => Str::random(60),
                ])->save();
            }

            // Never deliver or expose plaintext in audit logs. On delivery
            // failure this transaction rolls back the credential change.
            $deliver?->__invoke();
        });
    }
}
