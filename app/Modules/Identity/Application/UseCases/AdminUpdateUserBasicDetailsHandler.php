<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\ValueObjects\PasswordVO;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AdminUpdateUserBasicDetailsHandler
{
    /** @param array{first_name: ?string, last_name: ?string, middle_name: ?string, birth_date: ?string, password: ?string} $details */
    public function handle(User $actor, int $userId, array $details): void
    {
        if (! $actor->isConfirmed() || ! $actor->hasSystemRole(UserSystemRoleEnum::SUPERADMIN)) {
            throw new AuthorizationException('Редактировать базовые данные пользователей может только superadmin.');
        }

        DB::transaction(function () use ($userId, $details): void {
            $user = User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            $profile = $user->profile()->lockForUpdate()->first();

            if ($profile === null) {
                $profile = $user->profile()->create([]);
            }

            $profile->fill([
                'first_name' => $details['first_name'],
                'last_name' => $details['last_name'],
                'middle_name' => $details['middle_name'],
                'birth_date' => $details['birth_date'],
            ])->save();

            if ($details['password'] !== null) {
                $user->forceFill([
                    'password' => PasswordVO::fromString($details['password'])->value,
                    'password_updated_at' => now(),
                    'is_temporary_password' => true,
                    'remember_token' => Str::random(60),
                ])->save();
            }
        });
    }
}
