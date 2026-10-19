<?php

namespace App\Modules\Identity\Application\Services;

use App\Modules\Identity\Application\DTO\CredentialAuthenticationResult;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Events\UserFirstLogin;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Shared credential checks for browser sessions and stateless mobile/API login.
 * Never authenticates the request or issues a token by itself.
 */
final class CredentialAuthenticationService
{
    public function __construct(
        private readonly UserLoginResolver $userLoginResolver,
        private readonly CanonicalUserResolver $canonicalUserResolver,
    ) {}

    public function verify(string $login, string $password): CredentialAuthenticationResult
    {
        $candidates = $this->userLoginResolver->resolveCandidates($login);
        if ($candidates->isEmpty()) {
            return $this->invalid();
        }

        /** @var User $identityUser */
        $identityUser = $candidates->first();
        $canonicalUser = $this->canonicalUserResolver->resolve($identityUser);
        $credentialUsers = User::query()->whereIn('id', $canonicalUser->identityIds())->get();

        $matching = $credentialUsers
            ->filter(fn (User $user): bool => $user->password !== null && Hash::check($password, $user->password))
            ->values();

        if ($matching->isEmpty()) {
            return $this->invalid();
        }

        if ($canonicalUser->status === UserStatusEnum::BLOCKED
            || $credentialUsers->contains(fn (User $user): bool => $user->status === UserStatusEnum::BLOCKED)) {
            return new CredentialAuthenticationResult(
                null, 'error', 'Ваш аккаунт заблокирован. Пожалуйста, обратитесь в поддержку.', 403,
            );
        }

        $temporary = $matching->contains(fn (User $user): bool => (bool) $user->is_temporary_password);

        return new CredentialAuthenticationResult(
            $canonicalUser,
            $temporary ? 'warning' : 'success',
            $temporary
                ? 'Вы вошли с временным паролем. Пожалуйста, смените пароль в настройках профиля.'
                : 'Вы успешно вошли в систему.',
            200,
            $temporary,
        );
    }

    public function recordFirstLogin(User $user): void
    {
        $marked = User::query()->whereKey($user->id)->whereNull('first_logged_in_at')
            ->update(['first_logged_in_at' => now()]);

        if ($marked === 1) {
            event(new UserFirstLogin((int) $user->id));
        }
    }

    private function invalid(): CredentialAuthenticationResult
    {
        return new CredentialAuthenticationResult(
            null, 'error', 'Неверный логин, контакт или пароль.', 401,
        );
    }
}
