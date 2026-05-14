<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Contact\Domain\Enums\ContactStatusEnum;
use App\Modules\ContactVerification\Application\Services\ContactVerificationManager;
use App\Modules\ContactVerification\Domain\Enums\ContactVerificationPurposeEnum;
use App\Modules\Identity\Application\Contracts\ContactValueCheckerContract;
use App\Modules\Identity\Application\Contracts\UserReadRepositoryContract;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class LoginHandler
{
    public function __construct(
        private readonly ContactValueCheckerContract $contactValueChecker,
        private readonly UserReadRepositoryContract $users,
        private readonly ContactVerificationManager $contactVerificationManager,
    ) {
    }

    /**
     * @return array{status:string,message:string,httpStatus:int}
     */
    public function handle(string $login, string $password, bool $remember): array
    {
        $normalizedLogin = mb_strtolower(trim($login));
        $isContact = $this->contactValueChecker->isContact($normalizedLogin);
        $user = $this->users->findByLoginOrContact($normalizedLogin, $isContact);
        $matchedContact = $isContact ? $user?->contacts->first() : null;

        if ($user === null) {
            return [
                'status' => 'auth_failed',
                'message' => 'Логин не найден.',
                'httpStatus' => 404,
            ];
        }

        if ($user->status === UserStatusEnum::BLOCKED || $user->status === UserStatusEnum::REMOVED) {
            return [
                'status' => 'user_blocked',
                'message' => 'Аккаунт заблокирован.',
                'httpStatus' => 403,
            ];
        }

        if ($user->status !== UserStatusEnum::CONFIRMED && ! $user->is_temp_password) {
            return [
                'status' => 'user_unconfirmed',
                'message' => 'Аккаунт не подтверждён.',
                'httpStatus' => 403,
            ];
        }

        if (
            ! filled($user->password)
            || ! Hash::check($password, (string) $user->password)
        ) {
            return [
                'status' => 'auth_failed',
                'message' => 'Неверный логин или пароль.',
                'httpStatus' => 422,
            ];
        }

        Auth::login($user, $remember);

        if (
            $user->is_temp_password
            && $matchedContact !== null
            && $matchedContact->status === ContactStatusEnum::UNVERIFIED
        ) {
            $verification = $this->contactVerificationManager->findPendingForContact(
                $matchedContact,
                ContactVerificationPurposeEnum::SITE_CONTACT_FIRST,
            );

            if ($verification !== null) {
                $this->contactVerificationManager->complete($verification);
            }
        }

        return [
            'status' => 'authenticated',
            'message' => 'Вход выполнен.',
            'httpStatus' => 200,
        ];
    }
}
