<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Application\Services\GlobalOperationalPermissionManager;
use App\Modules\Identity\Application\Services\UsernameSuggestionService;
use App\Modules\Identity\Application\Services\UserOperationalPermissionChecker;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Exceptions\InvalidIdentityValueException;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\ValueObjects\UsernameVO;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AdminCreateUserHandler
{
    public function __construct(
        private readonly GlobalOperationalPermissionManager $management,
        private readonly UserOperationalPermissionChecker $permissions,
        private readonly UsernameSuggestionService $usernames,
        private readonly CreateUserAccountHandler $accounts,
        private readonly CurrentActorResolver $actors,
    ) {}

    public function canCreate(?User $actor): bool
    {
        return $actor !== null
            && $this->management->mayManage($actor)
            && $this->permissions->allows($actor, Permission::BACKOFFICE_ACCESS)
            && $this->permissions->allows($actor, Permission::BACKOFFICE_USER_CREATE)
            && $this->permissions->allows($actor, Permission::EDIT_USER_PERSONAL_DATA);
    }

    public function handle(User $actor, string $username): User
    {
        if (! $this->canCreate($actor)) {
            throw new AuthorizationException('Нет разрешения на создание пользователя.');
        }

        try {
            $username = UsernameVO::fromString($username)->value;
        } catch (InvalidIdentityValueException $exception) {
            throw ValidationException::withMessages(['username' => $exception->getMessage()]);
        }

        try {
            return DB::transaction(function () use ($actor, $username): User {
                $actor = $actor->canonical()->fresh();
                if (! $this->canCreate($actor)) {
                    throw new AuthorizationException('Нет разрешения на создание пользователя.');
                }

                if (! $this->usernames->isAvailable($username)) {
                    throw ValidationException::withMessages(['username' => 'Этот логин уже занят.']);
                }

                $user = $this->accounts->handle(
                    username: $username,
                    password: null,
                    registrationChannel: UserRegistrationChannelEnum::OTHER,
                    systemRole: UserSystemRoleEnum::USER,
                    status: UserStatusEnum::UNCONFIRMED,
                );

                AuditLog::query()->create([
                    'actor_id' => $this->actors->resolve($actor, null)?->id,
                    'auditable_type' => User::class,
                    'auditable_id' => $user->id,
                    'event' => 'admin_user_created',
                    'old_values' => [],
                    'new_values' => ['username' => $username, 'status' => UserStatusEnum::UNCONFIRMED->value],
                    'metadata' => ['source' => 'filament', 'actor_user_id' => $actor->id],
                ]);

                return $user;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            // Check after rollback; a competing writer may have taken the login.
            if (! $this->usernames->isAvailable($username)) {
                throw ValidationException::withMessages(['username' => 'Этот логин уже занят.']);
            }

            throw $exception;
        }
    }
}
