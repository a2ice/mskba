<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Identity\Application\DTO\ProfileDTO;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Events\UserRegistered;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Facades\DB;

final class RegisterUserHandler
{
    public function __construct(
        private readonly CreateUserAccountHandler $createUserAccount,
    ) {}

    public function handle(
        string $username,
        string $password,
        ?UserParticipationRoleEnum $participantRole = null,
        ?ProfileDTO $profile = null,
    ): User {
        $user = DB::transaction(function () use ($username, $password, $participantRole, $profile): User {
            $user = $this->createUserAccount->handle(
                username: $username,
                password: $password,
                registrationChannel: UserRegistrationChannelEnum::SITE_FULL_REGISTRATION,
                systemRole: UserSystemRoleEnum::USER,
                status: UserStatusEnum::UNCONFIRMED,
                isTemporaryPassword: false,
                profile: $profile,
            );

            if ($participantRole !== null) {
                $user->participationRoles()->create([
                    'role' => $participantRole,
                    'status' => UserParticipationRoleStatusEnum::ACTIVE,
                    'assigned_at' => now(),
                    'assigned_by' => $user->id,
                    'assigner' => UserParticipationRoleAssignerEnum::USER,
                    'comment' => 'Выбрана пользователем при регистрации.',
                ]);
            }

            return $user->loadMissing('profile', 'participationRoles');
        });

        // event(new UserRegistered((int) $user->id));

        return $user;
    }
}
