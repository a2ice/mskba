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
        $actor = $actor->canonical();
        if (! $actor->isConfirmed() || ! $actor->hasSystemRole(UserSystemRoleEnum::SUPERADMIN)) {
            throw new AuthorizationException('Редактировать базовые данные пользователей может только superadmin.');
        }

        DB::transaction(function () use ($userId, $details): void {
            $requested = User::query()->findOrFail($userId);
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

            /** @var User $user */
            $user = $identityUsers->firstWhere('id', $canonicalId) ?? User::query()->whereKey($canonicalId)->lockForUpdate()->firstOrFail();
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
                $password = PasswordVO::fromString($details['password'])->value;
                $changedAt = now();

                foreach ($identityUsers as $identityUser) {
                    if ((int) $identityUser->id === $canonicalId) {
                        $identityUser->forceFill([
                            'password' => $password,
                            'password_updated_at' => $changedAt,
                            'is_temporary_password' => true,
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
            }
        });
    }
}
