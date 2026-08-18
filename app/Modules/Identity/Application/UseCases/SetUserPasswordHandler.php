<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\ValueObjects\PasswordVO;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SetUserPasswordHandler
{
    public function handle(User $user, ?string $currentPassword, string $newPassword): void
    {
        $password = PasswordVO::fromString($newPassword)->value;

        DB::transaction(function () use ($user, $currentPassword, $password): void {
            $requested = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $canonicalId = (int) ($requested->canonical_user_id ?? $requested->id);
            $identityUsers = User::query()
                ->where(function ($query) use ($canonicalId): void {
                    $query
                        ->whereKey($canonicalId)
                        ->orWhere('canonical_user_id', $canonicalId);
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            /** @var User|null $canonical */
            $canonical = $identityUsers->firstWhere('id', $canonicalId);
            if ($canonical === null) {
                throw ValidationException::withMessages([
                    'current_password' => 'Не удалось определить основной аккаунт. Повторите попытку.',
                ]);
            }

            if ($canonical->password !== null) {
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
                        'is_temporary_password' => false,
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
        });
    }
}
