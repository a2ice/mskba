<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;

final class RegisterUserHandler
{
    public function __construct(
        private readonly CreateUserAccountHandler $createUserAccount,
    ) {}

    public function handle(string $username, string $password): User
    {
        return $this->createUserAccount->handle(
            username: $username,
            password: $password,
            registrationChannel: UserRegistrationChannelEnum::SITE_FULL_REGISTRATION,
            systemRole: UserSystemRoleEnum::USER,
            status: UserStatusEnum::UNCONFIRMED,
            isTemporaryPassword: false,
        );
    }
}
