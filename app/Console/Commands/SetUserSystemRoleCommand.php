<?php

namespace App\Console\Commands;

use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('identity:set-system-role {role : System role value} {username : User login}')]
#[Description('Set user system role by username')]
class SetUserSystemRoleCommand extends Command
{
    public function handle(): int
    {
        $roleValue = (string) $this->argument('role');
        $username = (string) $this->argument('username');

        $role = UserSystemRoleEnum::tryFrom($roleValue);

        if ($role === null) {
            $availableRoles = collect(UserSystemRoleEnum::cases())
                ->map(fn (UserSystemRoleEnum $role): string => $role->value)
                ->implode(', ');

            $this->error("Unknown system role [{$roleValue}]. Available roles: {$availableRoles}.");

            return self::FAILURE;
        }

        $user = User::query()
            ->where('username', $username)
            ->first();

        if ($user === null) {
            $this->error("User [{$username}] was not found.");

            return self::FAILURE;
        }

        $previousRole = $user->system_role;

        $user->update([
            'system_role' => $role,
        ]);

        $previousRoleValue = $previousRole?->value ?? 'null';

        $this->info("User [{$username}] system role changed from [{$previousRoleValue}] to [{$role->value}].");

        return self::SUCCESS;
    }
}
