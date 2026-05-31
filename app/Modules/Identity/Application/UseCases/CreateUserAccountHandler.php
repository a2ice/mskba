<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\ValueObjects\PasswordVO;
use App\Modules\Identity\Domain\ValueObjects\UsernameVO;
use Illuminate\Support\Facades\DB;

final class CreateUserAccountHandler
{
    /**
     * @param  array<string, mixed>  $profile
     */
    public function handle(
        string $username,
        string $password,
        UserRegistrationChannelEnum $registrationChannel,
        UserSystemRoleEnum $systemRole = UserSystemRoleEnum::USER,
        UserStatusEnum $status = UserStatusEnum::UNCONFIRMED,
        bool $isTemporaryPassword = false,
        array $profile = [],
    ): User {
        $username = UsernameVO::fromString($username)->value;
        $password = PasswordVO::fromString($password)->value;

        return DB::transaction(function () use ($username, $password, $registrationChannel, $systemRole, $status, $isTemporaryPassword, $profile): User {
            $user = User::query()->create([
                'username' => $username,
                'password' => $password,
                'password_updated_at' => now(),
                'is_temporary_password' => $isTemporaryPassword,
                'registration_channel' => $registrationChannel,
                'system_role' => $systemRole,
                'status' => $status,
            ]);

            $user->createProfile($profile);

            return $user->load('profile');
        });
    }
}
