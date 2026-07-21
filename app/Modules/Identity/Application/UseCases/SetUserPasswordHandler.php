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
            $lockedUser = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedUser->password !== null
                && ($currentPassword === null || ! Hash::check($currentPassword, $lockedUser->password))) {
                throw ValidationException::withMessages([
                    'current_password' => 'Текущий пароль указан неверно.',
                ]);
            }

            $lockedUser->forceFill([
                'password' => $password,
                'password_updated_at' => now(),
                'is_temporary_password' => false,
                'remember_token' => Str::random(60),
            ])->save();
        });
    }
}
